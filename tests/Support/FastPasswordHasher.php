<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\PasswordHasherInterface;

/**
 * Hacheur RÉSERVÉ AUX TESTS (#207) : bcrypt au coût minimal (≈ 2 ms au lieu de ≈ 90 ms avec le coût de production). Les
 * tests créent et vérifient des mots de passe partout, ce coût dominait la durée de la suite. Ses hachages se vérifient
 * avec le vrai hacheur (le coût est inscrit dans le hachage), et inversement ; le vrai hacheur garde son propre test
 * (NativePasswordHasherTest) qui protège sa force.
 */
final class FastPasswordHasher implements PasswordHasherInterface
{
    public function hash(string $plainPassword): string
    {
        return password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    public function verify(string $plainPassword, string $hash): bool
    {
        return password_verify($plainPassword, $hash);
    }
}
