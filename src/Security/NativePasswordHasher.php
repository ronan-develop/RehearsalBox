<?php

declare(strict_types=1);

namespace App\Security;

final class NativePasswordHasher implements PasswordHasherInterface
{
    public function hash(string $plainPassword): string
    {
        return password_hash($plainPassword, PASSWORD_DEFAULT);
    }

    public function verify(string $plainPassword, string $hash): bool
    {
        return password_verify($plainPassword, $hash);
    }

    /** Un hachage jeté coûte autant qu'une vérification au même coût, et suit automatiquement le coût par défaut. */
    public function simulateVerification(string $plainPassword): void
    {
        password_hash($plainPassword, PASSWORD_DEFAULT);
    }
}
