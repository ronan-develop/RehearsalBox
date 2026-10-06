<?php

declare(strict_types=1);

namespace App\Deploy;

/**
 * Garde-fou de `database/seed.php`, script DESTRUCTIF (il vide utilisateurs, groupes et créneaux, puis recrée des comptes au
 * mot de passe connu). Trois verrous indépendants : ligne de commande seulement, application locale seulement (URL publique
 * = environnement réel = refus, y compris quand elle est inconnue) et accord explicite. Le script est de plus exclu de
 * l'archive de déploiement : ce garde-fou protège aussi d'un lancement par erreur sur un poste de travail mal configuré.
 */
final class SeedGuard
{
    public const FLAG = '--force-local';

    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]', '::1'];

    /**
     * @param list<string> $argv arguments de la ligne de commande
     *
     * @throws \RuntimeException le seed est refusé (le message n'affiche jamais l'URL ni aucune valeur de configuration)
     */
    public static function assertSafe(string $sapi, string $baseUrl, array $argv): void
    {
        if ($sapi !== 'cli') {
            throw new \RuntimeException('Seed refusé : il ne tourne qu\'en ligne de commande.');
        }
        $host = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?? ''));
        if (!in_array($host, self::LOCAL_HOSTS, true)) {
            throw new \RuntimeException('Seed refusé : l\'application n\'est pas configurée en local (app.base_url).');
        }
        if (!in_array(self::FLAG, $argv, true)) {
            throw new \RuntimeException('Seed refusé : il efface les données ; relancez avec ' . self::FLAG . ' pour confirmer que c\'est une base de développement.');
        }
    }
}
