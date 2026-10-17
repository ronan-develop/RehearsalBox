<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Destination permise après une connexion (#180, #412, #462) : le lien d'un e-mail ramène dans la conversation, sur la page d'une alerte, d'une demande d'échange ou des réservations à valider.
 * Liste blanche stricte : les pages de la messagerie, la page d'un échange et une liste FERMÉE de pages d'exploitation, de mesures et de comptabilité (#362, lien du rappel au trésorier) (aucune URL externe,
 * aucun paramètre, aucun retour à la ligne) : pas de redirection ouverte. Ces pages restent protégées par leur contrôleur : un
 * compte qui n'est pas le propriétaire y obtient le même 404 qu'avant.
 */
final class SafeRedirect
{
    private const MESSAGING_PAGES = '#\A/messages(?:/archives|/new/[1-9][0-9]{0,9}|/[1-9][0-9]{0,9})?\z#';

    /** Page d'une demande d'échange (#462) : le lien de l'e-mail « demande d'échange ». */
    private const EXCHANGE_PAGE = '#\A/exchanges/[1-9][0-9]{0,9}\z#';

    private const ADMIN_PAGES = ['/admin/bookings', '/admin/ops', '/admin/ops/production', '/admin/metrics', '/admin/metrics/security', '/admin/metrics/load', '/admin/accounting'];

    public static function afterLogin(mixed $path): ?string
    {
        if (!is_string($path)) {
            return null;
        }

        return preg_match(self::MESSAGING_PAGES, $path) === 1 || preg_match(self::EXCHANGE_PAGE, $path) === 1 || in_array($path, self::ADMIN_PAGES, true) ? $path : null;
    }
}
