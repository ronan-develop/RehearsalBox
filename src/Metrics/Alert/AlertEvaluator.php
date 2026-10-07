<?php

declare(strict_types=1);

namespace App\Metrics\Alert;

use App\Metrics\MetricEventType;
use App\Metrics\Report\HealthStatus;
use App\Metrics\Report\Load\DegradationDetector;
use App\Metrics\Report\MetricsReaderInterface;
use App\Metrics\Report\Security\AnomalyDetector;
use App\Metrics\Report\Security\AnomalyKind;
use App\Metrics\Report\Thresholds;
use Psr\Clock\ClockInterface;

/**
 * Quelles alertes sont en cours MAINTENANT (#199) : une alerte n'existe que lorsque l'indicateur est ROUGE selon les mêmes seuils que
 * les pages du tableau de bord (un orange se lit sur la page, il ne réveille personne). Ne décide ni de l'envoi ni du délai.
 * Les phrases ne contiennent que des chiffres : aucune adresse, aucun contenu, pas même l'empreinte d'une adresse.
 */
final class AlertEvaluator
{
    public function __construct(
        private readonly MetricsReaderInterface $reader,
        private readonly Thresholds $thresholds,
        private readonly DegradationDetector $degradation,
        private readonly AnomalyDetector $anomalies,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return list<Alert> */
    public function evaluate(): array
    {
        $now = $this->clock->now();
        $alerts = [];

        $snapshot = $this->reader->latestSnapshot();
        if ($snapshot !== null) {
            $cronMinutes = $snapshot->lastCronAt === null ? null : intdiv($now->getTimestamp() - $snapshot->lastCronAt->getTimestamp(), 60);
            if ($this->red('cron_minutes', $cronMinutes, true)) {
                $alerts[] = new Alert(AlertType::CronSilent, sprintf('Le cron des relances n\'a pas tourné depuis %d h.', intdiv((int) $cronMinutes, 60)));
            }
            if ($this->red('backup_hours', $snapshot->backupAgeHours, true)) {
                $alerts[] = new Alert(AlertType::BackupOld, sprintf('La dernière sauvegarde de la base date de %d h.', (int) $snapshot->backupAgeHours));
            }
            $diskMb = $snapshot->diskFreeBytes === null ? null : intdiv($snapshot->diskFreeBytes, 1024 * 1024);
            if ($this->red('disk_free_mb', $diskMb, false)) {
                $alerts[] = new Alert(AlertType::DiskLow, sprintf('Il reste %d Mo d\'espace disque libre.', (int) $diskMb));
            }
        }

        $recent = $this->reader->loadByHour($now->modify('-1 hour'));
        $errors = array_sum(array_column($recent, 'errors'));
        if ($this->red('errors_5xx', $errors, true)) {
            $alerts[] = new Alert(AlertType::ServerErrors, sprintf('%d erreurs serveur (5xx) sur la dernière heure.', $errors));
        }

        $verdict = $this->degradation->assess($this->reader->loadByHour($now->modify('-171 hours')));
        if ($verdict->status === HealthStatus::Critical) {
            $alerts[] = new Alert(AlertType::Degradation, $verdict->messages[0]);
        }

        $attacks = array_filter(
            $this->anomalies->detect($this->reader->securityEvents($now->modify('-1 hour'))),
            static fn ($a): bool => $a->kind !== AnomalyKind::Scanner,
        );
        if ($attacks !== []) {
            $alerts[] = new Alert(AlertType::Attack, sprintf('%d rafale(s) ou série(s) d\'échecs de connexion repérée(s) sur la dernière heure.', count($attacks)));
        }

        return $alerts;
    }

    private function red(string $metric, int|float|null $value, bool $higherIsWorse): bool
    {
        return $this->thresholds->status($metric, $value, $higherIsWorse) === HealthStatus::Critical;
    }
}
