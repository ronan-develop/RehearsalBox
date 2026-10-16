<?php

declare(strict_types=1);

namespace App\Accounting\Presenter;

/** Mois en français (« novembre 2025 ») sans dépendre de la locale du serveur. */
final class MonthLabel
{
    private const NAMES = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    public static function of(\DateTimeImmutable $month): string
    {
        return self::NAMES[(int) $month->format('n') - 1] . ' ' . $month->format('Y');
    }

    /** Forme courte pour l'en-tête d'un tableau : « nov. 25 ». */
    public static function short(\DateTimeImmutable $month): string
    {
        return mb_substr(self::NAMES[(int) $month->format('n') - 1], 0, 4) . '. ' . $month->format('y');
    }
}
