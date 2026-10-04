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
}
