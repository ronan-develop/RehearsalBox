<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Destination permise après une connexion (#180) : le lien d'un e-mail ramène dans la conversation. Liste blanche stricte
 * des seules pages de la messagerie (aucune URL externe, aucun paramètre, aucun retour à la ligne) : pas de redirection ouverte.
 */
final class SafeRedirect
{
    private const MESSAGING_PAGES = '#\A/messages(?:/archives|/new/[1-9][0-9]{0,9}|/[1-9][0-9]{0,9})?\z#';

    public static function afterLogin(mixed $path): ?string
    {
        return is_string($path) && preg_match(self::MESSAGING_PAGES, $path) === 1 ? $path : null;
    }
}
