<?php

declare(strict_types=1);

namespace App\Accounting\Import\Rules;

/** Libellé de banque rendu comparable (#362) : minuscules, accents retirés, chiffres et ponctuation réduits à une espace. Sert au motif d'une règle et à sa recherche. */
final class LabelNormalizer
{
    public const PATTERN_MAX = 60;
    public const PATTERN_MIN = 4;

    public static function normalize(string $label): string
    {
        $text = mb_strtolower($label);
        $plain = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC')?->transliterate($text);
        $text = (string) preg_replace('/[^a-z]+/u', ' ', $plain === false || $plain === null ? $text : $plain);

        return trim((string) preg_replace('/ {2,}/', ' ', $text));
    }

    /** Motif proposé pour retenir un libellé : son texte normalisé, coupé à 60 caractères sans couper un mot ; null s'il est trop court pour être sûr. */
    public static function defaultPattern(string $label): ?string
    {
        $pattern = self::normalize($label);
        if (mb_strlen($pattern) > self::PATTERN_MAX) {
            $pattern = mb_substr($pattern, 0, self::PATTERN_MAX);
            $space = mb_strrpos($pattern, ' ');
            $pattern = $space === false ? $pattern : mb_substr($pattern, 0, $space);
        }

        return mb_strlen($pattern) >= self::PATTERN_MIN ? $pattern : null;
    }
}
