<?php

declare(strict_types=1);

namespace App\Metrics\Report\Security;

use App\Metrics\Chart\SvgChart;
use App\Metrics\MetricEventType;
use App\Metrics\Report\MetricsPeriod;
use App\Metrics\Report\MetricsReaderInterface;
use App\Metrics\Report\StatusCard;
use App\Metrics\Report\Thresholds;
use App\Metrics\Report\TimeBuckets;
use Psr\Clock\ClockInterface;

/** Compose la page « Sécurité et anomalies » (#197) : compteurs de la période, graphiques par heure ou par jour, anomalies détectées. */
final class SecurityReportBuilder
{
    private const COUNTED = [
        MetricEventType::LoginFailed,
        MetricEventType::AccessDenied,
        MetricEventType::CsrfFailed,
        MetricEventType::RateLimited,
        MetricEventType::PasswordResetRequested,
        MetricEventType::NotFound,
    ];

    public function __construct(
        private readonly MetricsReaderInterface $reader,
        private readonly AnomalyDetector $detector,
        private readonly Thresholds $thresholds,
        private readonly ClockInterface $clock,
        private readonly \DateTimeZone $localTimezone,
    ) {
    }

    public function build(MetricsPeriod $period): SecurityReport
    {
        $buckets = new TimeBuckets($period, $this->clock->now(), $this->localTimezone);
        $since = $buckets->sinceUtc();

        $series = [];
        foreach (self::COUNTED as $type) {
            $series[$type->value] = $buckets->emptySeries();
        }
        foreach ($this->reader->eventsByHour($since, self::COUNTED) as $hour => $byType) {
            $key = $buckets->keyOf($hour);
            if ($key === null) {
                continue;
            }
            foreach ($byType as $type => $count) {
                $series[$type][$key] += $count;
            }
        }
        $total = static fn (MetricEventType $type): int => array_sum($series[$type->value]);

        $events = $this->reader->securityEvents($since);
        $scans = count(array_filter($events, static fn (SecurityEvent $e): bool => $e->type === MetricEventType::NotFound && ScannerPaths::matches($e->route)));
        $labels = $buckets->labels();
        $chart = new SvgChart();

        return new SecurityReport(
            $period,
            [
                new StatusCard('Échecs de connexion', (string) $total(MetricEventType::LoginFailed), $this->thresholds->status('login_failures', $total(MetricEventType::LoginFailed), true), 'sur la période'),
                new StatusCard('Accès refusés (403)', (string) $total(MetricEventType::AccessDenied), $this->thresholds->status('access_denied', $total(MetricEventType::AccessDenied), true), 'réponses uniformes'),
                new StatusCard('Jetons CSRF refusés', (string) $total(MetricEventType::CsrfFailed), $this->thresholds->status('csrf_failures', $total(MetricEventType::CsrfFailed), true), 'sur la période'),
                new StatusCard('Limites de débit atteintes', (string) $total(MetricEventType::RateLimited), $this->thresholds->status('rate_limited', $total(MetricEventType::RateLimited), true), 'sur la période'),
                new StatusCard('Mots de passe oubliés demandés', (string) $total(MetricEventType::PasswordResetRequested), null, 'information'),
                new StatusCard('Pages introuvables', (string) $total(MetricEventType::NotFound), null, $scans . ' sur des chemins de balayage'),
            ],
            [
                $chart->bars('Échecs de connexion', 'échecs', $labels, array_values($series[MetricEventType::LoginFailed->value]), 'err'),
                $chart->bars('Accès refusés (403)', 'refus', $labels, array_values($series[MetricEventType::AccessDenied->value]), 'err'),
                $chart->bars('Limites de débit atteintes', 'limites', $labels, array_values($series[MetricEventType::RateLimited->value]), 'err'),
                $chart->bars('Pages introuvables (404)', 'pages', $labels, array_values($series[MetricEventType::NotFound->value])),
            ],
            $this->detector->detect($events),
        );
    }
}
