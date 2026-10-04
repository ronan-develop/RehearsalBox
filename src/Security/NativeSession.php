<?php

declare(strict_types=1);

namespace App\Security;

final class NativeSession implements SessionInterface
{
    /**
     * @param array<string, mixed> $server
     *
     * @return array{cookie_httponly: bool, cookie_samesite: string, cookie_secure: bool}
     */
    public static function cookieOptions(array $server): array
    {
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        $forwardedProto = strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''));

        return [
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => ($https !== '' && $https !== 'off') || $forwardedProto === 'https',
        ];
    }

    public function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start(self::cookieOptions($_SERVER));
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
