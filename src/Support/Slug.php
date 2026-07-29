<?php

declare(strict_types=1);

namespace App\Support;

final class Slug
{
    public static function from(string $value): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $lowercased = strtolower($transliterated);
        $dashed = preg_replace('/[^a-z0-9]+/', '-', $lowercased) ?? '';

        return trim($dashed, '-');
    }
}
