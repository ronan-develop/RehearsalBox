<?php

declare(strict_types=1);

namespace App\Operations\Backup;

use App\Backup\DatabaseBackup;
use App\Backup\Restore\BackupCatalog;
use App\Backup\Restore\BackupEntry;
use App\Backup\Restore\RestoreStatus;
use App\Backup\Restore\SchemaTools;
use App\Metrics\Report\HealthStatus;
use App\Metrics\Report\HumanDuration;
use App\Metrics\Report\HumanSize;
use App\Metrics\Report\StatusCard;
use App\Metrics\Report\Thresholds;
use Psr\Clock\ClockInterface;

/**
 * Les dumps de la base vus par l'onglet Sauvegardes (#375) : fraîcheur du quotidien et de l'avant-déploiement, place occupée,
 * rétention, dernière restauration, base temporaire de l'essai à blanc. Lecture seule, aucune exception : un relevé impossible
 * donne « inconnu ». Seuls des états, des dates et des tailles sortent d'ici, jamais un chemin ni le message d'une restauration.
 * Le quotidien se juge sur les `db-*` SEULS : un dump d'avant déploiement récent ne doit pas masquer un quotidien en panne.
 */
final class BackupHealthBuilder
{
    public function __construct(
        private readonly BackupCatalog $catalog,
        private readonly RestoreStatus $restore,
        private readonly ?SchemaTools $schemas,
        private readonly ?string $scratchSchema,
        private readonly Thresholds $thresholds,
        private readonly ClockInterface $clock,
        private readonly \DateTimeZone $zone,
    ) {
    }

    /** @return array<string, StatusCard> indexées par indicateur (daily_dump, predeploy_dump, backups_size, retention, last_restore, scratch_empty) */
    public function cards(): array
    {
        $entries = $this->catalog->list();
        $daily = $this->ofKind($entries, DatabaseBackup::DAILY);
        $pre = $this->ofKind($entries, DatabaseBackup::PRE_DEPLOY);

        return [
            'daily_dump' => $this->dailyCard($daily),
            'predeploy_dump' => $this->preDeployCard($pre),
            'backups_size' => new StatusCard('Place occupée', $entries === [] ? '—' : HumanSize::of(array_sum(array_map(static fn (BackupEntry $e): int => $e->bytes, $entries))), null, count($entries) . ' dump(s)'),
            'retention' => $this->retentionCard(count($daily), count($pre)),
            'last_restore' => $this->restoreCard(),
            'scratch_empty' => $this->scratchCard(),
        ];
    }

    /** @return list<BackupEntry> les plus récents d'abord */
    public function recent(int $count = 10): array
    {
        return array_slice($this->catalog->list(), 0, max(0, $count));
    }

    /** @return array{labels: list<string>, values: list<float>} taille des 14 derniers dumps quotidiens (une chute signale un dump tronqué), du plus ancien au plus récent */
    public function dailyTrend(): array
    {
        $labels = [];
        $values = [];
        foreach (array_reverse(array_slice($this->ofKind($this->catalog->list(), DatabaseBackup::DAILY), 0, DatabaseBackup::RETENTION[DatabaseBackup::DAILY])) as $entry) {
            $labels[] = $entry->createdAt->setTimezone($this->zone)->format('d/m');
            $values[] = round($entry->bytes / 1024, 1);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /** @param list<BackupEntry> $daily */
    private function dailyCard(array $daily): StatusCard
    {
        if (!$this->catalog->exists()) {
            return new StatusCard('Dernier dump quotidien', '—', HealthStatus::Unknown, 'dossier des sauvegardes non configuré');
        }
        if ($daily === []) {
            return new StatusCard('Dernier dump quotidien', 'aucun', HealthStatus::Critical, 'aucun dump quotidien dans le dossier');
        }

        return new StatusCard('Dernier dump quotidien', 'il y a ' . $this->age($daily[0]), $this->thresholds->status('backup_hours', $this->ageHours($daily[0]), true), $this->localDate($daily[0]));
    }

    /** @param list<BackupEntry> $pre */
    private function preDeployCard(array $pre): StatusCard
    {
        if ($pre === []) {
            return new StatusCard('Dernier dump avant déploiement', '—', null, 'aucun pour l\'instant');
        }

        return new StatusCard('Dernier dump avant déploiement', 'il y a ' . $this->age($pre[0]), null, $this->localDate($pre[0]));
    }

    private function retentionCard(int $daily, int $pre): StatusCard
    {
        $keepDaily = DatabaseBackup::RETENTION[DatabaseBackup::DAILY];
        $keepPre = DatabaseBackup::RETENTION[DatabaseBackup::PRE_DEPLOY];
        $status = match (true) {
            !$this->catalog->exists() => HealthStatus::Unknown,
            $daily === 0 => HealthStatus::Critical,
            $daily > $keepDaily || $pre > $keepPre => HealthStatus::Warning,
            default => HealthStatus::Ok,
        };
        $hint = $status === HealthStatus::Warning ? 'au-delà de la rétention : la rotation ne passe plus' : 'quotidiens · avant déploiement';

        return new StatusCard('Rétention', "{$daily}/{$keepDaily} · {$pre}/{$keepPre}", $status, $hint);
    }

    private function restoreCard(): StatusCard
    {
        $state = $this->restore->read();
        if ($state === null) {
            return new StatusCard('Dernière restauration', 'jamais', null, 'aucune restauration depuis la page');
        }
        $when = $this->formatted($state['updatedAt']);

        return match ($state['state']) {
            'done' => new StatusCard('Dernière restauration', 'réussie', HealthStatus::Ok, $when),
            'failed' => new StatusCard('Dernière restauration', 'échouée', HealthStatus::Warning, $when . ' · détail sur la page de restauration'),
            'running' => new StatusCard('Dernière restauration', 'en cours', null, 'commencée ' . $when),
            default => new StatusCard('Dernière restauration', '—', HealthStatus::Unknown, ''),
        };
    }

    private function scratchCard(): StatusCard
    {
        if ($this->schemas === null || $this->scratchSchema === null || $this->scratchSchema === '') {
            return new StatusCard('Base temporaire', 'non configurée', null, 'l\'essai à blanc est sauté');
        }

        try {
            $empty = $this->schemas->isEmpty($this->scratchSchema);
        } catch (\Throwable) {
            return new StatusCard('Base temporaire', '—', HealthStatus::Unknown, 'lecture impossible');
        }

        return $empty
            ? new StatusCard('Base temporaire', 'vide', HealthStatus::Ok, 'comme attendu')
            : new StatusCard('Base temporaire', 'non vide', HealthStatus::Warning, 'elle doit rester vide en permanence');
    }

    /**
     * @param list<BackupEntry> $entries
     *
     * @return list<BackupEntry>
     */
    private function ofKind(array $entries, string $kind): array
    {
        return array_values(array_filter($entries, static fn (BackupEntry $e): bool => $e->kind === $kind));
    }

    private function ageHours(BackupEntry $entry): int
    {
        return max(0, intdiv($this->clock->now()->getTimestamp() - $entry->createdAt->getTimestamp(), 3600));
    }

    private function age(BackupEntry $entry): string
    {
        return HumanDuration::ofMinutes($this->ageHours($entry) * 60);
    }

    private function localDate(BackupEntry $entry): string
    {
        return $entry->createdAt->setTimezone($this->zone)->format('d/m/Y à H:i');
    }

    private function formatted(string $iso): string
    {
        try {
            return (new \DateTimeImmutable($iso))->setTimezone($this->zone)->format('d/m/Y à H:i');
        } catch (\Exception) {
            return '—';
        }
    }
}
