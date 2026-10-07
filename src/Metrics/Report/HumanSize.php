<?php

declare(strict_types=1);

namespace App\Metrics\Report;

/** Tailles en octets lisibles (Ko, Mo, Go), séparateur décimal français. */
final class HumanSize
{
    public static function of(int $bytes): string
    {
        foreach (['Go' => 1024 ** 3, 'Mo' => 1024 ** 2, 'Ko' => 1024] as $unit => $size) {
            if ($bytes >= $size) {
                return number_format($bytes / $size, 1, ',', ' ') . ' ' . $unit;
            }
        }

        return $bytes . ' o';
    }
}
