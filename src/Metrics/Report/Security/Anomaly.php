<?php

declare(strict_types=1);

namespace App\Metrics\Report\Security;

/** Une anomalie repérée (on la rapporte, on ne bloque jamais). L'empreinte est tronquée : aucune adresse en clair. */
final class Anomaly
{
    public function __construct(
        public readonly AnomalyKind $kind,
        public readonly string $fingerprint,
        public readonly int $count,
        public readonly \DateTimeImmutable $firstAt,
        public readonly \DateTimeImmutable $lastAt,
    ) {
    }
}
