<?php

declare(strict_types=1);

namespace App\Support;

final class Initials
{
    public static function from(string $displayName): string
    {
        $parts = preg_split('/\s+/', trim($displayName), -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) >= 2) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
        }

        return mb_strtoupper(mb_substr($displayName, 0, 2));
    }
}
