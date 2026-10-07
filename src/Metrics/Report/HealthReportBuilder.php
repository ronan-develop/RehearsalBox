<?php

declare(strict_types=1);

namespace App\Metrics\Report;

use App\Metrics\Chart\SvgChart;
use App\Metrics\HealthSnapshot;
use App\Metrics\MetricEventType;
use Psr\Clock\ClockInterface;

/**
 * Compose la page « Santé et e-mails » (#196) : regroupe les mesures de la période en points (une heure sur 24 h, un jour au-delà,
 * en heure locale), calcule les indicateurs, leur état selon les seuils configurés et dessine les graphiques.
 */
final class HealthReportBuilder
{
    public function __construct(
        private readonly MetricsReaderInterface $reader,
        private readonly Thresholds $thresholds,
        private readonly ClockInterface $clock,
        private readonly \DateTimeZone $localTimezone,
    ) {
    }

    public function build(MetricsPeriod $period): HealthReport
    {
        $now = $this->clock->now();
        $buckets = new TimeBuckets($period, $now, $this->localTimezone);
        $since = $buckets->sinceUtc();

        $requests = $buckets->emptySeries();
        $errors = $requests;
        $duration = $requests;
        foreach ($this->reader->requestsByHour($since) as $hour => $row) {
            $key = $buckets->keyOf($hour);
            if ($key !== null) {
                $requests[$key] += $row['requests'];
                $errors[$key] += $row['errors'];
                $duration[$key] += $row['durationMs'];
            }
        }
        $sent = $buckets->emptySeries();
        $failed = $sent;
        foreach ($this->reader->eventsByHour($since, [MetricEventType::MailSent, MetricEventType::MailFailed]) as $hour => $byType) {
            $key = $buckets->keyOf($hour);
            if ($key !== null) {
                $sent[$key] += $byType[MetricEventType::MailSent->value] ?? 0;
                $failed[$key] += $byType[MetricEventType::MailFailed->value] ?? 0;
            }
        }

        $average = [];
        foreach ($requests as $key => $count) {
            $average[] = $count === 0 ? 0 : (int) round($duration[$key] / $count);
        }
        $labels = $buckets->labels();
        $chart = new SvgChart();

        return new HealthReport(
            $period,
            $this->cards(array_sum($requests), array_sum($errors), array_sum($duration), array_sum($sent), array_sum($failed), $this->reader->latestSnapshot(), $now),
            [
                $chart->bars('Requêtes servies', 'requêtes', $labels, array_values($requests)),
                $chart->bars('Erreurs serveur (5xx)', 'erreurs', $labels, array_values($errors), 'err'),
                $chart->line('Temps de réponse moyen', 'ms', $labels, $average),
                $chart->bars('E-mails envoyés', 'e-mails', $labels, array_values($sent)),
                $chart->bars('E-mails en échec', 'e-mails', $labels, array_values($failed), 'err'),
            ],
        );
    }

    /** @return list<StatusCard> */
    private function cards(int $requests, int $errors, int $durationMs, int $sent, int $failed, ?HealthSnapshot $snapshot, \DateTimeImmutable $now): array
    {
        $availability = $requests > 0 ? 100 - 100 * $errors / $requests : null;
        $average = $requests > 0 ? $durationMs / $requests : null;
        $lastCron = $snapshot?->lastCronAt;
        $cronMinutes = $lastCron === null ? null : intdiv($now->getTimestamp() - $lastCron->getTimestamp(), 60);
        $backupHours = $snapshot?->backupAgeHours;
        $disk = $snapshot?->diskFreeBytes;
        $diskMb = $disk === null ? null : intdiv($disk, 1024 * 1024);
        $database = $snapshot?->dbSizeBytes;
        $release = $snapshot?->releaseMarker;
        $php = $snapshot === null ? '—' : $snapshot->phpVersion;
        $taken = $snapshot === null ? 'aucun relevé pour l\'instant' : 'relevé du ' . $snapshot->takenAt->setTimezone($this->localTimezone)->format('d/m à H:i');

        return [
            new StatusCard('Disponibilité', $availability === null ? '—' : number_format($availability, 2, ',', ' ') . ' %', $this->thresholds->status('availability_percent', $availability, false), $requests . ' requêtes'),
            new StatusCard('Erreurs serveur (5xx)', (string) $errors, $this->thresholds->status('errors_5xx', $requests > 0 ? $errors : null, true), 'sur la période'),
            new StatusCard('Temps de réponse moyen', $average === null ? '—' : round($average) . ' ms', $this->thresholds->status('response_ms', $average, true), 'côté serveur'),
            new StatusCard('E-mails en échec', (string) $failed, $this->thresholds->status('mail_failures', $sent + $failed > 0 ? $failed : null, true), $sent . ' envoyé(s) sur la période'),
            new StatusCard('Cron des relances', $cronMinutes === null ? '—' : 'il y a ' . $this->duration($cronMinutes), $this->thresholds->status('cron_minutes', $cronMinutes, true), 'dernier passage connu'),
            new StatusCard('Dernière sauvegarde', $backupHours === null ? '—' : 'il y a ' . $this->duration($backupHours * 60), $this->thresholds->status('backup_hours', $backupHours, true), $taken),
            new StatusCard('Espace disque libre', $disk === null ? '—' : HumanSize::of($disk), $this->thresholds->status('disk_free_mb', $diskMb, false), $taken),
            new StatusCard('Taille de la base', $database === null ? '—' : HumanSize::of($database), null, $taken),
            new StatusCard('Version servie', $release ?? '—', null, 'PHP ' . $php),
        ];
    }

    private function duration(int $minutes): string
    {
        return match (true) {
            $minutes < 60 => max(0, $minutes) . ' min',
            $minutes < 48 * 60 => intdiv($minutes, 60) . ' h',
            default => intdiv($minutes, 1440) . ' j',
        };
    }
}
