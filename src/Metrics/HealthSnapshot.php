<?php

declare(strict_types=1);

namespace App\Metrics;

/** L'état du serveur à un instant (#195) : de quoi voir venir un disque plein, une sauvegarde oubliée ou un cron muet. */
final class HealthSnapshot
{
    public function __construct(
        public readonly \DateTimeImmutable $takenAt,
        public readonly ?int $diskFreeBytes,
        public readonly ?int $dbSizeBytes,
        public readonly ?int $backupAgeHours,
        public readonly ?\DateTimeImmutable $lastCronAt,
        public readonly ?string $releaseMarker,
        public readonly string $phpVersion,
        public readonly ?float $load1m,
    ) {
    }
}
