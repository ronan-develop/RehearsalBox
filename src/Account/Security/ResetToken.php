<?php

declare(strict_types=1);

namespace App\Account\Security;

/** Jeton à usage unique (réinitialisation, alerte) : 256 bits aléatoires, seule l'empreinte est stockée. */
final class ResetToken
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
