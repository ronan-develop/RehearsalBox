<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\User;
use App\Account\Repository\UserRepositoryInterface;
use App\Account\Security\PasswordHasherInterface;
use App\Account\Exception\UserValidationException;

/**
 * « Mot de passe actuel » redemandé avant une opération sensible (changer de mot de passe, d'adresse e-mail) :
 * règle unique, identique à la connexion. Compte verrouillé = refusé même avec le bon mot de passe ; un mot de
 * passe faux compte comme une tentative de connexion échouée (jusqu'au verrouillage).
 */
final class CurrentPasswordVerifier
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordHasherInterface $passwordHasher,
    ) {
    }

    /** @throws UserValidationException erreur sur le champ « currentPassword » */
    public function assertMatches(User $user, string $currentPassword, \DateTimeImmutable $now): void
    {
        if ($user->isLocked($now)) {
            throw new UserValidationException(['currentPassword' => 'Compte temporairement verrouillé. Réessayez plus tard.']);
        }

        if (!$this->passwordHasher->verify($currentPassword, $user->passwordHash())) {
            $this->userRepository->recordFailedLogin($user->id(), AuthService::MAX_FAILED_ATTEMPTS, $now, AuthService::LOCK_DURATION);

            throw new UserValidationException(['currentPassword' => 'Mot de passe actuel incorrect.']);
        }
    }
}
