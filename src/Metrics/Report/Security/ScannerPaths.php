<?php

declare(strict_types=1);

namespace App\Metrics\Report\Security;

/** Chemins que seuls des robots de balayage demandent (le site n'en sert aucun) : fichiers de configuration, panneaux d'administration d'autres outils… */
final class ScannerPaths
{
    private const FRAGMENTS = [
        '/.env', '/.git', '/.aws', '/.ssh', '/.ds_store', 'wp-login', 'wp-admin', 'wp-content', 'wp-includes', 'xmlrpc',
        'phpmyadmin', 'phpinfo', '/vendor/', '/cgi-bin', '/actuator', '/server-status', '/etc/passwd', '.php', '.sql', '.bak', '.zip', '.tar',
    ];

    public static function matches(string $path): bool
    {
        $path = strtolower($path);
        foreach (self::FRAGMENTS as $fragment) {
            if (str_contains($path, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
