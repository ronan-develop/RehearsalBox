<?php

declare(strict_types=1);

namespace App\Metrics\Collection;

use App\Metrics\HealthSnapshot;
use Psr\Clock\ClockInterface;

/**
 * Relève l'état du serveur : espace disque, taille de la base, âge de la dernière sauvegarde, dernier passage du cron des
 * relances, version servie, version de PHP, charge système (si l'hébergeur l'autorise). Chaque relevé indisponible vaut null :
 * un hébergeur mutualisé n'expose pas tout, et un relevé manquant ne doit jamais empêcher les autres.
 */
final class HealthProbe
{
    /**
     * @param string  $diskPath      dossier dont on mesure l'espace libre
     * @param ?string $backupDir     dossier des sauvegardes (fichiers *.sql*), null si non configuré
     * @param string  $cronLogPath   journal du cron : sa date de modification est le dernier passage
     * @param string  $releaseFile   marqueur de release écrit par le déploiement
     */
    public function __construct(
        private readonly MetricsRepositoryInterface $repository,
        private readonly ClockInterface $clock,
        private readonly string $diskPath,
        private readonly ?string $backupDir,
        private readonly string $cronLogPath,
        private readonly string $releaseFile,
    ) {
    }

    public function snapshot(): HealthSnapshot
    {
        $now = $this->clock->now();
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;

        return new HealthSnapshot(
            takenAt: $now,
            diskFreeBytes: ($free = @disk_free_space($this->diskPath)) === false ? null : (int) $free,
            dbSizeBytes: $this->repository->databaseSizeBytes(),
            backupAgeHours: $this->backupAgeHours($now),
            lastCronAt: ($cron = @filemtime($this->cronLogPath)) === false ? null : (new \DateTimeImmutable('@' . $cron))->setTimezone($now->getTimezone()),
            releaseMarker: is_file($this->releaseFile) ? substr(trim((string) file_get_contents($this->releaseFile)), 0, 64) : null,
            phpVersion: PHP_VERSION,
            load1m: is_array($load) ? round($load[0], 2) : null,
        );
    }

    private function backupAgeHours(\DateTimeImmutable $now): ?int
    {
        if ($this->backupDir === null || !is_dir($this->backupDir)) {
            return null;
        }
        $newest = 0;
        foreach (glob(rtrim($this->backupDir, '/') . '/*.sql*') ?: [] as $file) {
            $newest = max($newest, (int) filemtime($file));
        }

        return $newest === 0 ? null : max(0, intdiv($now->getTimestamp() - $newest, 3600));
    }
}
