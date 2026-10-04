<?php

declare(strict_types=1);

namespace App\Security;

final class PasswordPolicy
{
    private const MIN_LENGTH = 8;

    /** Message d'erreur si le mot de passe ne respecte pas la règle, null sinon. */
    public function violation(string $plainPassword): ?string
    {
        if (strlen($plainPassword) < self::MIN_LENGTH) {
            return 'Le mot de passe doit faire au moins ' . self::MIN_LENGTH . ' caractères.';
        }

        return null;
    }
}
