<?php

declare(strict_types=1);

namespace App\Metrics\Report\Load;

use App\Metrics\Chart\SvgChart;
use App\Metrics\DurationHistogram;
use App\Metrics\Report\MetricsPeriod;
use App\Metrics\Report\MetricsReaderInterface;
use App\Metrics\Report\StatusCard;
use App\Metrics\Report\Thresholds;
use App\Metrics\Report\TimeBuckets;
use Psr\Clock\ClockInterface;

/**
 * Compose la page « Charge » (#198) : requêtes par minute et pics, temps de réponse (médiane et 95e centile estimés par tranches),
 * pic de mémoire de PHP, routes les plus sollicitées (dont le polling de la messagerie, premier suspect si le trafic grossit) et
 * indicateur de dégradation comparé à une base glissante de 7 jours à volume comparable.
 */
final class LoadReportBuilder
{
    /** Route du polling de la messagerie (motif) : mise en évidence parce que son coût croît avec le nombre de conversations ouvertes. */
    public const POLLING_ROUTE = '/api/conversations/{id}/updates';

    private const BASELINE_HOURS = 7 * 24;

    public function __construct(
        private readonly MetricsReaderInterface $reader,
        private readonly DegradationDetector $detector,
        private readonly Thresholds $thresholds,
        private readonly ClockInterface $clock,
        private readonly \DateTimeZone $localTimezone,
    ) {
    }

    public function build(MetricsPeriod $period): LoadReport
    {
        $now = $this->clock->now();
        $buckets = new TimeBuckets($period, $now, $this->localTimezone);
        $perMinute = $period->bucketsByDay() ? 1440 : 60;

        $requests = $buckets->emptySeries();
        $memory = $requests;
        $histograms = array_map(static fn (): array => array_fill(0, DurationHistogram::BUCKETS, 0), $requests);
        $load = $this->reader->loadByHour($buckets->sinceUtc());
        foreach ($load as $hour => $row) {
            $key = $buckets->keyOf($hour);
            if ($key === null) {
                continue;
            }
            $requests[$key] += $row['requests'];
            $memory[$key] = max($memory[$key], $row['memoryKb']);
            foreach ($row['buckets'] as $i => $count) {
                $histograms[$key][$i] += $count;
            }
        }

        $rate = array_map(static fn (int $n): float => round($n / $perMinute, 1), array_values($requests));
        $median = array_map(static fn (array $h): int => DurationHistogram::percentile($h, 0.5) ?? 0, array_values($histograms));
        $p95 = array_map(static fn (array $h): int => DurationHistogram::percentile($h, 0.95) ?? 0, array_values($histograms));
        $total = array_fill(0, DurationHistogram::BUCKETS, 0);
        foreach ($histograms as $h) {
            foreach ($h as $i => $count) {
                $total[$i] += $count;
            }
        }

        $routes = $this->routes($buckets->sinceUtc());
        $degradation = $this->detector->assess($this->reader->loadByHour($now->modify('-' . (self::BASELINE_HOURS + 3) . ' hours')));
        $labels = $buckets->labels();
        $chart = new SvgChart();
        $peakMemoryKb = $memory === [] ? 0 : max($memory);
        $peakRate = $rate === [] ? 0.0 : max($rate);
        $polling = array_values(array_filter($routes, static fn (array $r): bool => $r['polling']))[0] ?? null;

        return new LoadReport(
            $period,
            [
                new StatusCard('Verdict de dégradation', $degradation->status->label(), $degradation->status, $degradation->messages[0]),
                new StatusCard('Requêtes par minute (pic)', number_format($peakRate, 1, ',', ' '), null, 'moyenne de la période : ' . number_format(array_sum($requests) / max(1, count($requests) * $perMinute), 1, ',', ' ')),
                new StatusCard('Temps de réponse médian', self::bound(DurationHistogram::percentile($total, 0.5)), null, 'estimé par tranches, borne haute'),
                new StatusCard('95e centile', self::bound(DurationHistogram::percentile($total, 0.95)), $this->thresholds->status('response_ms', DurationHistogram::percentile($total, 0.95), true), 'estimé par tranches, borne haute'),
                new StatusCard('Pic de mémoire PHP', $peakMemoryKb === 0 ? '—' : number_format($peakMemoryKb / 1024, 1, ',', ' ') . ' Mo', null, 'par requête, maximum de la période'),
                new StatusCard('Polling de la messagerie', $polling === null ? '—' : $polling['timeShare'] . ' % du temps', null, $polling === null ? 'aucune mesure' : number_format($polling['requests'], 0, ',', ' ') . ' requêtes sur la période'),
            ],
            [
                $chart->bars('Requêtes par minute (moyenne par ' . ($period->bucketsByDay() ? 'jour' : 'heure') . ')', 'requêtes/min', $labels, $rate),
                $chart->line('Temps de réponse médian (borne haute)', 'ms', $labels, $median),
                $chart->line('95e centile du temps de réponse (borne haute)', 'ms', $labels, $p95),
                $chart->bars('Pic de mémoire PHP', 'Mo', $labels, array_map(static fn (int $kb): float => round($kb / 1024, 1), array_values($memory))),
            ],
            $routes,
            $degradation,
        );
    }

    /** @return list<array{route: string, requests: int, averageMs: int, maxMs: int, timeShare: int, polling: bool}> */
    private function routes(\DateTimeImmutable $since): array
    {
        $totals = $this->reader->routeTotals($since, 10);
        $allTime = array_sum(array_column($totals, 'durationMs'));

        return array_map(static fn (array $r): array => [
            'route' => $r['route'],
            'requests' => $r['requests'],
            'averageMs' => $r['requests'] > 0 ? (int) round($r['durationMs'] / $r['requests']) : 0,
            'maxMs' => $r['maxMs'],
            'timeShare' => $allTime > 0 ? (int) round(100 * $r['durationMs'] / $allTime) : 0,
            'polling' => $r['route'] === self::POLLING_ROUTE,
        ], $totals);
    }

    private static function bound(?int $milliseconds): string
    {
        return match (true) {
            $milliseconds === null => '—',
            $milliseconds === DurationHistogram::OPEN_ENDED => '> 1000 ms',
            default => '≤ ' . $milliseconds . ' ms',
        };
    }
}
