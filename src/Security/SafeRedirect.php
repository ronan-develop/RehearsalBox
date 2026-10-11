<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Destination permise après une connexion (#180, #412) : le lien d'un e-mail ramène dans la conversation ou sur la page d'une alerte.
 * Liste blanche stricte : les pages de la messagerie et une liste FERMÉE de pages d'exploitation et de mesures (aucune URL externe,
 * aucun paramètre, aucun retour à la ligne) : pas de redirection ouverte. Ces pages restent protégées par leur contrôleur : un
 * compte qui n'est pas le propriétaire y obtient le même 404 qu'avant.
 */
final class SafeRedirect
{
    private const MESSAGING_PAGES = '#\A/messages(?:/archives|/new/[1-9][0-9]{0,9}|/[1-9][0-9]{0,9})?\z#';

    private const ADMIN_PAGES = ['/admin/ops', '/admin/ops/production', '/admin/metrics', '/admin/metrics/security', '/admin/metrics/load'];

    public static function afterLogin(mixed $path): ?string
    {
        if (!is_string($path)) {
            return null;
        }

        return preg_match(self::MESSAGING_PAGES, $path) === 1 || in_array($path, self::ADMIN_PAGES, true) ? $path : null;
    }
}
