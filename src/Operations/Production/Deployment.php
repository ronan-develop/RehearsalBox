<?php

declare(strict_types=1);

namespace App\Operations\Production;

/** Une ligne du journal des déploiements (#396), déjà validée. `startedAt` est l'heure du POSTE de dev : affichée, jamais utilisée dans un seuil. */
final class Deployment
{
    public function __construct(
        public readonly string $release,
        public readonly string $commit,
        public readonly \DateTimeImmutable $startedAt,
        public readonly int $durationSeconds,
        public readonly bool $succeeded,
        public readonly bool $switched,
        public readonly string $checks,
    ) {
    }
}
