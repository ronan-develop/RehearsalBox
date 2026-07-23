<?php

declare(strict_types=1);

namespace App\Security;

final class CsrfTokenManager
{
    private const SESSION_KEY = 'csrf_token';

    public function __construct(private readonly SessionInterface $session)
    {
    }

    public function getToken(): string
    {
        $existing = $this->session->get(self::SESSION_KEY);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    public function isValid(string $candidate): bool
    {
        $expected = $this->session->get(self::SESSION_KEY);
        if (!is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, $candidate);
    }
}
