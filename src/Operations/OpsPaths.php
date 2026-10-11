<?php

declare(strict_types=1);

namespace App\Operations;

/**
 * Emplacements fixes de l'exploitation (#375) sous le dossier de l'installation (`ops.base_dir`, ex. ~/rehearsalbox). Des noms
 * écrits en dur, jamais un paramètre de requête : la page ne peut lire que ces fichiers. Sans dossier configuré, aucun chemin.
 */
final class OpsPaths
{
    private readonly ?string $base;

    public function __construct(?string $baseDir)
    {
        $this->base = $baseDir === null || $baseDir === '' ? null : rtrim($baseDir, '/');
    }

    public function backupLog(): ?string
    {
        return $this->under('shared/backup.log');
    }

    public function cronLog(): ?string
    {
        return $this->under('shared/storage/logs/cron.log');
    }

    public function collectLog(): ?string
    {
        return $this->under('shared/storage/logs/collect.log');
    }

    public function declarations(): ?string
    {
        return $this->under('shared/ops-declarations.json');
    }

    public function deploymentsLog(): ?string
    {
        return $this->under('shared/deployments.jsonl');
    }

    public function releasesDir(): ?string
    {
        return $this->under('releases');
    }

    private function under(string $relative): ?string
    {
        return $this->base === null ? null : $this->base . '/' . $relative;
    }
}
