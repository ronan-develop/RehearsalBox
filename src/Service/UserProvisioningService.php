<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Repository\Contract\UserRepositoryInterface;
use App\Security\PasswordHasherInterface;

final class UserProvisioningService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordHasherInterface $passwordHasher,
    ) {
    }

    public function create(string $email, string $displayName, UserRole $role, string $plainPassword): User
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new \InvalidArgumentException('Adresse email invalide.');
        }
        if ($displayName === '' || strlen($displayName) > 100) {
            throw new \InvalidArgumentException('Nom affiché requis (100 caractères maximum).');
        }
        if ($plainPassword === '') {
            throw new \InvalidArgumentException('Mot de passe requis.');
        }
        if ($this->userRepository->findByEmail($email) !== null) {
            throw new \InvalidArgumentException('Un compte existe déjà avec cet email.');
        }

        return $this->userRepository->save(new User(
            id: 0,
            email: $email,
            passwordHash: $this->passwordHasher->hash($plainPassword),
            displayName: $displayName,
            role: $role,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
    }
}
