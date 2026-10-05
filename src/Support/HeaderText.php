<?php

declare(strict_types=1);

namespace App\Support;

/** Texte saisi par un utilisateur placé dans un en-tête d'e-mail : une seule ligne, sans caractère de contrôle ni de mise en forme (injection d'en-tête). */
final class HeaderText
{
    public static function oneLine(string $text, int $maxLength = 100): string
    {
        $clean = trim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $text));

        return mb_substr($clean, 0, $maxLength);
    }
}
