<?php

declare(strict_types=1);

namespace App\Operations\Backup;

use App\Backup\DatabaseBackup;
use App\Backup\Restore\BackupCatalog;
use App\Metrics\Report\HealthStatus;
use App\Metrics\Report\HumanDuration;
use App\Metrics\Report\StatusCard;
use App\Metrics\Report\Thresholds;
use App\Operations\BoundedFile;
use App\Operations\OpsPaths;
use Psr\Clock\ClockInterface;

/**
 * Ce qui protège les dumps et les fait exister, pour l'onglet Sauvegardes (#375) : le fichier de clés (sans dump, les messages sont
 * illisibles sans lui), sa sauvegarde et la copie hors serveur (déclarées par le propriétaire), les tâches planifiées (dump quotidien,
 * relances, collecte des mesures) jugées sur la date de modification de leur journal. Lecture seule ; seuls états et dates sortent.
 */
final class ProtectionHealthBuilder
{
    public function __construct(
        private readonly KeyFileProbe $keyFile,
        private readonly Declarations $declarations,
        private readonly OpsPaths $paths,
        private readonly BackupCatalog $catalog,
        private readonly Thresholds $thresholds,
        private readonly ClockInterface $clock,
        private readonly \DateTimeZone $zone,
    ) {
    }

    /** @return array<string, StatusCard> indexées par indicateur (key_file, key_saved, offsite, task_backup, task_reminders, task_collect) */
    public function cards(): array
    {
        return [
            'key_file' => $this->keyFileCard(),
            'key_saved' => $this->declaredCard('Clé rangée dans KeePass', $this->declarations->keySavedAt(), 'php bin/ops-declare.php key-saved'),
            'offsite' => $this->offsiteCard(),
            'task_backup' => $this->backupTaskCard(),
            'task_reminders' => $this->logCard('Cron des relances', $this->paths->cronLog(), 'cron_minutes'),
            'task_collect' => $this->logCard('Collecte des mesures', $this->paths->collectLog(), 'collect_minutes'),
        ];
    }

    private function keyFileCard(): StatusCard
    {
        $result = $this->keyFile->inspect();
        if ($result['state'] !== KeyFileState::Ok) {
            return new StatusCard('Clé de chiffrement', 'à corriger', HealthStatus::Critical, $result['state']->label());
        }
        $hint = $result['keys'] . ' clé(s)' . ($result['transition'] ? ' · transition non terminée' : '');

        return new StatusCard('Clé de chiffrement', 'en place', HealthStatus::Ok, $hint);
    }

    private function declaredCard(string $label, ?\DateTimeImmutable $at, string $command): StatusCard
    {
        if ($at === null) {
            return new StatusCard($label, 'jamais déclarée', HealthStatus::Warning, 'à déclarer par SSH : ' . $command);
        }

        return new StatusCard($label, 'il y a ' . $this->since($at), null, $this->local($at));
    }

    private function offsiteCard(): StatusCard
    {
        $at = $this->declarations->offsiteAt();
        if ($at === null) {
            return new StatusCard('Copie hors du serveur', 'jamais', HealthStatus::Warning, 'bin/fetch-backup.sh la déclare après chaque copie');
        }
        $days = intdiv(max(0, $this->clock->now()->getTimestamp() - $at->getTimestamp()), 86400);

        return new StatusCard('Copie hors du serveur', 'il y a ' . $this->since($at), $this->thresholds->status('offsite_days', $days, true), $this->local($at));
    }

    /** Le journal de la tâche est plus récent que le dernier dump quotidien (d'au moins 1 h) : elle a tourné et n'a pas produit de dump. */
    private function backupTaskCard(): StatusCard
    {
        $ran = BoundedFile::mtime($this->paths->backupLog(), $this->zone);
        if ($ran === null) {
            return new StatusCard('Tâche du dump quotidien', '—', HealthStatus::Unknown, 'journal introuvable');
        }
        $lastDump = null;
        foreach ($this->catalog->list() as $entry) {
            if ($entry->kind === DatabaseBackup::DAILY) {
                $lastDump = $entry->createdAt;
                break;
            }
        }
        if ($lastDump === null || $ran->getTimestamp() - $lastDump->getTimestamp() >= 3600) {
            return new StatusCard('Tâche du dump quotidien', 'dernier passage en échec', HealthStatus::Critical, 'lire shared/backup.log sur le serveur');
        }
        $hours = intdiv(max(0, $this->clock->now()->getTimestamp() - $ran->getTimestamp()), 3600);

        return new StatusCard('Tâche du dump quotidien', 'il y a ' . $this->since($ran), $this->thresholds->status('backup_task_hours', $hours, true), 'dernier passage connu');
    }

    private function logCard(string $label, ?string $log, string $threshold): StatusCard
    {
        $ran = BoundedFile::mtime($log, $this->zone);
        if ($ran === null) {
            return new StatusCard($label, '—', HealthStatus::Unknown, 'journal introuvable');
        }
        $minutes = intdiv(max(0, $this->clock->now()->getTimestamp() - $ran->getTimestamp()), 60);

        return new StatusCard($label, 'il y a ' . HumanDuration::ofMinutes($minutes), $this->thresholds->status($threshold, $minutes, true), 'dernier passage connu');
    }

    private function local(\DateTimeImmutable $at): string
    {
        return $at->setTimezone($this->zone)->format('d/m/Y à H:i');
    }

    private function since(\DateTimeImmutable $at): string
    {
        return HumanDuration::ofMinutes(intdiv(max(0, $this->clock->now()->getTimestamp() - $at->getTimestamp()), 60));
    }
}
