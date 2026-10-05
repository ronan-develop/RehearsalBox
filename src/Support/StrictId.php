<?php

declare(strict_types=1);

namespace App\Support;

use App\Security\Exception\AccessDeniedException;

/**
 * Identifiant numérique strict venu d'une URL ou d'un corps de requête : entier positif, ou chaîne de 1 à 10 chiffres
 * sans zéro en tête. Tout le reste (zéro, signe, décimale, espace, tableau, injection…) est refusé.
 */
final class StrictId
{
    public static function from(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /** Comme `from()`, mais un identifiant invalide est refusé comme un accès interdit (aucun indice sur l'existence). @throws AccessDeniedException */
    public static function orDenied(mixed $value): int
    {
        return self::from($value) ?? throw new AccessDeniedException('Accès refusé.');
    }
}
