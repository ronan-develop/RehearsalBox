<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Règle unique du nom affiché (création d'un compte par un admin, modification par l'utilisateur) :
 * le nom est affiché partout (en-tête, listes, e-mails), donc il est borné et sans caractère de
 * contrôle ni de mise en forme (saut de ligne, ESC, inversion du sens du texte…).
 */
final class DisplayNamePolicy
{
    public const MAX_LENGTH = 100;

    public function normalize(string $displayName): string
    {
        return trim($displayName);
    }

    /** Message d'erreur si le nom (déjà normalisé) est refusé, null sinon. Les longueurs sont en caractères, pas en octets. */
    public function violation(string $displayName): ?string
    {
        if ($displayName === '') {
            return 'Le nom affiché est requis.';
        }
        if (mb_strlen($displayName) > self::MAX_LENGTH) {
            return 'Le nom affiché ne doit pas dépasser ' . self::MAX_LENGTH . ' caractères.';
        }
        if (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $displayName) === 1) {
            return 'Le nom affiché contient un caractère non autorisé.';
        }

        return null;
    }
}
