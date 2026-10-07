<?php

declare(strict_types=1);

namespace App\Metrics\Report\Load;

use App\Metrics\Report\HealthStatus;

/** Verdict de l'indicateur de dégradation : un état et des phrases qui disent pourquoi. */
final class Degradation
{
    /** @param list<string> $messages */
    public function __construct(
        public readonly HealthStatus $status,
        public readonly array $messages,
    ) {
    }
}
