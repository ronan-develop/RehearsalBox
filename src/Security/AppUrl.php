<?php

declare(strict_types=1);

namespace App\Security;

/** Ce que l'URL publique de l'application (`app.base_url`, jamais dérivée de la requête) dit du transport : une seule source pour le cookie `Secure` et HSTS. */
final class AppUrl
{
    public static function isHttps(string $baseUrl): bool
    {
        return str_starts_with(strtolower($baseUrl), 'https://');
    }
}
