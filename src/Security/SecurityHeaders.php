<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\Response;

/**
 * Politique d'en-têtes de sécurité appliquée à toute réponse dynamique, en un seul endroit (Kernel).
 *
 * Les en-têtes déjà posés par un contrôleur restent prioritaires (ex. `Referrer-Policy: no-referrer` sur les pages à jeton).
 * Aucun script inline ni ressource externe dans l'application : seuls les attributs `style=` (couleurs des groupes) sont tolérés.
 */
final class SecurityHeaders
{
    private const CONTENT_SECURITY_POLICY = "default-src 'self'; script-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; "
        . "img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    /** Durée courte tant que le HTTPS de bout en bout n'a pas fait ses preuves (30 jours, sans sous-domaines). */
    private const HSTS = 'max-age=2592000';

    public function __construct(private readonly bool $hsts = false)
    {
    }

    public function applyTo(Response $response): Response
    {
        return $response->withDefaultHeaders($this->defaults());
    }

    /** @return array<string, string> */
    private function defaults(): array
    {
        $headers = [
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cache-Control' => 'private, no-store',
        ];

        if ($this->hsts) {
            $headers['Strict-Transport-Security'] = self::HSTS;
        }

        return $headers;
    }
}
