<?php

declare(strict_types=1);

namespace App\Support;

/** Dates écrites en français pour les e-mails (« mercredi 7 octobre 2026 »), sans dépendre de la locale ni de l'extension intl du serveur. */
final class FrenchDate
{
    private const DAYS = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    private const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    public static function long(\DateTimeImmutable $date): string
    {
        return sprintf(
            '%s %d %s %d',
            self::DAYS[(int) $date->format('N') - 1],
            (int) $date->format('j'),
            self::MONTHS[(int) $date->format('n') - 1],
            (int) $date->format('Y'),
        );
    }
}
