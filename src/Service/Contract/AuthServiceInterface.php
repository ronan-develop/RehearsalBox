<?php

declare(strict_types=1);

namespace App\Service\Contract;

use App\Entity\User;

interface AuthServiceInterface
{
    /** Retourne null en cas d'échec (email inconnu, mot de passe incorrect, compte verrouillé) — jamais de distinction, cf. plan §10.4. */
    public function attempt(string $email, string $plainPassword): ?User;

    public function currentUser(): ?User;

    public function logout(): void;
}
