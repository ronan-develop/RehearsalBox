<?php

declare(strict_types=1);

namespace App\Security;

final class NativeSession implements SessionInterface
{
    public function __construct(private readonly bool $secureCookies)
    {
    }

    /**
     * Options du cookie de session (#223). `Secure` vient de la CONFIGURATION (jamais d'un en-tête que le client peut falsifier) ;
     * en HTTPS le nom porte le préfixe « __Host- » (le navigateur refuse alors tout cookie de ce nom qui ne serait pas Secure,
     * sans Domain et sur « / ») ; le mode strict ignore tout identifiant que le serveur n'a pas émis.
     *
     * @return array{cookie_httponly: bool, cookie_samesite: string, cookie_secure: bool, use_strict_mode: bool, name: string}
     */
    public static function cookieOptions(bool $secure): array
    {
        return [
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => $secure,
            'use_strict_mode' => true,
            'name' => $secure ? '__Host-rbsid' : 'rbsid',
        ];
    }

    public function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start(self::cookieOptions($this->secureCookies));
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        session_destroy();
    }
}
