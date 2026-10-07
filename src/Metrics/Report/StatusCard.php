<?php

declare(strict_types=1);

namespace App\Metrics\Report;

/** Une carte d'état : un libellé, une valeur déjà mise en forme, son état (null pour une simple information) et une précision. */
final class StatusCard
{
    public function __construct(
        public readonly string $label,
        public readonly string $value,
        public readonly ?HealthStatus $status,
        public readonly string $hint = '',
    ) {
    }
}
