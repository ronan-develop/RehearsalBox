<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Repository\Contract\UserRepositoryInterface;
use App\Security\PasswordHasherInterface;
use App\Service\Exception\UserValidationException;

final class UserProvisioningService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordHasherInterface $passwordHasher,
    ) {
    }

    /** @return array<string, string> message d'erreur par champ, vide si tout est valide */
    private function validate(string $email, string $displayName, string $plainPassword): array
    {
        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $errors['email'] = 'Adresse email invalide.';
        }
        if (strlen($plainPassword) < 8) {
            $errors['password'] = 'Le mot de passe doit faire au moins 8 caractères.';
        }
        if ($displayName === '' || strlen($displayName) > 100) {
            $errors['displayName'] = 'Nom affiché requis (100 caractères maximum).';
        }
        if ($errors === [] && $this->userRepository->findByEmail($email) !== null) {
            $errors['email'] = 'Un compte existe déjà avec cet email.';
        }

        return $errors;
    }

    public function create(string $email, string $displayName, UserRole $role, string $plainPassword): User
    {
        $errors = $this->validate($email, $displayName, $plainPassword);
        if ($errors !== []) {
            throw new UserValidationException($errors);
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
