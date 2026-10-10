<?php

declare(strict_types=1);

namespace App\Support;

/** Une couleur stockée n'est jamais émise dans un style ou un attribut que si c'est exactement #rrggbb : pas d'injection CSS ni HTML. */
final class SafeColor
{
    /** Couleur proposée à un nouveau groupe (« Orange moyen » de la grille du sélecteur, #383) : même valeur que DEFAULT_COLOR de color-palette.js. */
    public const DEFAULT = '#cb824d';

    public static function from(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A#[0-9a-fA-F]{6}\z/', $value) === 1 ? $value : null;
    }
}
