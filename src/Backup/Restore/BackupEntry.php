<?php

declare(strict_types=1);

namespace App\Backup\Restore;

/**
 * Une sauvegarde de la base présente dans le dossier (#241) : `db-<horodatage>.sql.gz` (quotidienne) ou
 * `pre-<horodatage>-<release>.sql.gz` (d'avant déploiement). `label` porte la « release » des sauvegardes pre-deploy.
 */
final class BackupEntry
{
    public function __construct(
        public readonly string $file,
        public readonly string $kind,
        public readonly \DateTimeImmutable $createdAt,
        public readonly int $bytes,
        public readonly string $label,
    ) {
    }
}
