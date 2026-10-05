<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\User;

interface UserRepositoryInterface
{
    public function findById(int $id): ?User;

    public function findByEmail(string $email): ?User;

    /** @return list<User> tous les comptes, par nom affiché */
    public function findAll(): array;

    /** Nombre d'administrateurs actifs (garde-fou : on ne désactive jamais le dernier). */
    public function countActiveAdmins(): int;

    public function save(User $user): User;

    /**
     * Compte un échec de connexion de façon ATOMIQUE (une seule requête : des tentatives simultanées ne s'écrasent pas)
     * et verrouille le compte dès que `$maxAttempts` échecs sont atteints.
     */
    public function recordFailedLogin(int $userId, int $maxAttempts, \DateTimeImmutable $now, string $lockDuration): void;

    /** Remet à zéro le compteur d'échecs et le verrou (connexion réussie, déblocage par un administrateur). */
    public function resetFailedLogins(int $userId): void;
}
