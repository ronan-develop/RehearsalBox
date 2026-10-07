<?php

declare(strict_types=1);

namespace App\Metrics\Report\Security;

use App\Metrics\MetricEventType;

/** Un évènement de sécurité tel que lu pour la détection : jamais d'adresse, seulement l'empreinte tronquée. */
final class SecurityEvent
{
    public function __construct(
        public readonly MetricEventType $type,
        public readonly string $route,
        public readonly ?string $ipHash,
        public readonly \DateTimeImmutable $at,
    ) {
    }
}
