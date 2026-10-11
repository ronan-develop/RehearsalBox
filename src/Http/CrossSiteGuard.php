<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Une mutation venue d'un AUTRE site (#426), en plus du jeton CSRF : l'en-tête `Sec-Fetch-Site` (posé par le navigateur, non
 * falsifiable par une page web) et `Origin` (doit désigner l'hôte servi). Un client sans ces en-têtes (outil en ligne de commande)
 * n'est pas refusé ici : il n'est pas un navigateur piégé, et le jeton reste exigé. Aucune configuration : l'hôte attendu est celui
 * de la requête (`Host`).
 */
final class CrossSiteGuard
{
    public static function isCrossSite(Request $request): bool
    {
        if ($request->header('Sec-Fetch-Site') === 'cross-site') {
            return true;
        }

        $origin = $request->header('Origin');
        if ($origin === null) {
            return false;
        }

        $parts = parse_url($origin);
        if ($origin === 'null' || !is_array($parts) || !isset($parts['host'])) {
            return true;
        }

        $originAuthority = self::authority($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));

        return $originAuthority !== self::authority((string) $request->header('Host', ''));
    }

    /** Hôte en minuscules, sans le port par défaut (80, 443). */
    private static function authority(string $authority): string
    {
        return (string) preg_replace('/:(80|443)\z/', '', strtolower(trim($authority)));
    }
}
