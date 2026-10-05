<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\PasswordHasherInterface;

/** Compte les opérations de hachage faites par le code testé (aucun mot de passe n'est conservé). */
final class CountingPasswordHasher implements PasswordHasherInterface
{
    public int $verifications = 0;
    public int $simulations = 0;
    private readonly FastPasswordHasher $inner;

    public function __construct()
    {
        $this->inner = new FastPasswordHasher();
    }

    public function hash(string $plainPassword): string
    {
        return $this->inner->hash($plainPassword);
    }

    public function verify(string $plainPassword, string $hash): bool
    {
        ++$this->verifications;

        return $this->inner->verify($plainPassword, $hash);
    }

    public function simulateVerification(string $plainPassword): void
    {
        ++$this->simulations;
        $this->inner->simulateVerification($plainPassword);
    }
}
