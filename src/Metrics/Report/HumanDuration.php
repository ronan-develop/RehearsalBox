<?php

declare(strict_types=1);

namespace App\Metrics\Report;

/** Durées lisibles à partir de minutes : « N min », « N h », puis « N j » au-delà de 48 h. */
final class HumanDuration
{
    public static function ofMinutes(int $minutes): string
    {
        return match (true) {
            $minutes < 60 => max(0, $minutes) . ' min',
            $minutes < 48 * 60 => intdiv($minutes, 60) . ' h',
            default => intdiv($minutes, 1440) . ' j',
        };
    }
}
