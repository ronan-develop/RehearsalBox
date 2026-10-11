<?php

declare(strict_types=1);

namespace App\Operations\Production;

use App\Backup\DatabaseBackup;
use App\Backup\Restore\BackupCatalog;
use App\Metrics\Report\HealthStatus;
use App\Metrics\Report\StatusCard;

/**
 * L'onglet Production (#375), à partir de ce qui a été lu : release servie, dernier déploiement, migrations, cible d'un retour arrière, dump
 * d'avant déploiement. Pas d'accès disque ici hormis le catalogue : la construction est pure et ne lève rien. Seuls des états, des
 * identifiants de release (motif strict), des noms de migration et des dates sortent d'ici ; jamais un chemin.
 * L'écart entre `main` et la production n'est pas affiché : il demanderait une connaissance du dépôt que le serveur n'a pas.
 */
final class ProductionReportBuilder
{
    public function __construct(
        private readonly DeploymentLog $log,
        private readonly ReleaseInventory $releases,
        private readonly MigrationStatus $migrations,
        private readonly BackupCatalog $catalog,
        private readonly string $currentRelease,
        private readonly \DateTimeZone $zone,
    ) {
    }

    /** @return array<string, StatusCard> indexées par indicateur */
    public function cards(): array
    {
        $releases = $this->releases->releases();

        return [
            'active_release' => $this->activeCard($releases),
            'last_deployment' => $this->lastDeploymentCard($this->log->recent(1)[0] ?? null),
            'migrations' => $this->migrationsCard(),
            'rollback' => $this->rollbackCard($releases),
            'predeploy_dump' => $this->predeployCard(),
        ];
    }

    /** @return list<Deployment> */
    public function deployments(int $limit = 10): array
    {
        return $this->log->recent($limit);
    }

    /** @return list<array{id: string, commit: string, at: ?\DateTimeImmutable, current: bool, rollbackTarget: bool}> */
    public function releases(): array
    {
        return $this->releases->releases();
    }

    /** @return array{labels: list<string>, values: list<int>} durée (secondes) des 10 derniers déploiements, du plus ancien au plus récent */
    public function durations(): array
    {
        $labels = [];
        $values = [];
        foreach (array_reverse($this->log->recent(10)) as $deployment) {
            $labels[] = $deployment->startedAt->setTimezone($this->zone)->format('d/m');
            $values[] = $deployment->durationSeconds;
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /** @param list<array{id: string, commit: string, at: ?\DateTimeImmutable, current: bool, rollbackTarget: bool}> $releases */
    private function activeCard(array $releases): StatusCard
    {
        if ($this->currentRelease === '') {
            return new StatusCard('Release servie', '—', HealthStatus::Unknown, 'marqueur de release absent');
        }
        $current = array_values(array_filter($releases, static fn (array $r): bool => $r['current']))[0] ?? null;
        $since = $current === null || $current['at'] === null ? '' : 'basculée le ' . $current['at']->format('d/m/Y à H:i');

        return new StatusCard('Release servie', $this->currentRelease, null, $since);
    }

    private function lastDeploymentCard(?Deployment $last): StatusCard
    {
        if ($last === null) {
            return new StatusCard('Dernier déploiement', 'aucun journalisé', null, 'le journal se remplit à partir du prochain déploiement');
        }
        $hint = $last->startedAt->setTimezone($this->zone)->format('d/m/Y à H:i') . ' (heure du poste de dev) · ' . $last->durationSeconds . ' s';

        return match (true) {
            $last->succeeded => new StatusCard('Dernier déploiement', 'réussi', HealthStatus::Ok, $hint),
            !$last->switched => new StatusCard('Dernier déploiement', 'échoué avant la bascule', HealthStatus::Warning, 'production inchangée · ' . $hint),
            default => new StatusCard('Dernier déploiement', 'basculé, contrôle échoué', HealthStatus::Critical, 'vérifier la release servie · ' . $hint),
        };
    }

    private function migrationsCard(): StatusCard
    {
        $status = $this->migrations->status();
        if ($status === null) {
            return new StatusCard('Migrations', '—', HealthStatus::Unknown, 'journal des migrations illisible');
        }
        $last = $status['applied'][0]['name'] ?? null;
        if ($status['pending'] === []) {
            return new StatusCard('Migrations', 'à jour', HealthStatus::Ok, $last === null ? 'aucune migration appliquée' : 'dernière : ' . $last);
        }

        return new StatusCard('Migrations', count($status['pending']) . ' en attente', HealthStatus::Warning, implode(', ', array_slice($status['pending'], 0, 3)));
    }

    /** @param list<array{id: string, commit: string, at: ?\DateTimeImmutable, current: bool, rollbackTarget: bool}> $releases */
    private function rollbackCard(array $releases): StatusCard
    {
        $target = array_values(array_filter($releases, static fn (array $r): bool => $r['rollbackTarget']))[0] ?? null;

        return $target === null
            ? new StatusCard('Retour arrière', 'impossible', null, 'aucune release précédente conservée')
            : new StatusCard('Retour arrière', $target['id'], null, 'cible de bin/rollback.sh');
    }

    private function predeployCard(): StatusCard
    {
        foreach ($this->catalog->list() as $entry) {
            if ($entry->kind === DatabaseBackup::PRE_DEPLOY && $entry->label === $this->currentRelease && $this->currentRelease !== '') {
                return new StatusCard('Dump d\'avant ce déploiement', 'présent', HealthStatus::Ok, $entry->createdAt->setTimezone($this->zone)->format('d/m/Y à H:i'));
            }
        }

        return new StatusCard('Dump d\'avant ce déploiement', 'absent', null, 'normal au premier déploiement ou sur une base vide');
    }
}
