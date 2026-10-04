<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\Contract\UserRepositoryInterface;
use App\Security\PasswordHasherInterface;
use App\Security\PasswordPolicy;
use App\Service\Exception\UserValidationException;

final class PasswordChangeService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordHasherInterface $passwordHasher,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly AccountSecurityService $accountSecurity,
    ) {
    }

    /**
     * L'identifiant de l'utilisateur vient de la session, jamais de la requête.
     *
     * @throws UserValidationException    erreurs par champ (mot de passe actuel, nouveau mot de passe, confirmation)
     * @throws \InvalidArgumentException  utilisateur introuvable
     */
    public function changePassword(
        int $userId,
        string $currentPassword,
        string $newPassword,
        string $confirmation,
        ?\DateTimeImmutable $now = null,
    ): User {
        $now ??= new \DateTimeImmutable();

        $user = $this->userRepository->findById($userId);
        if ($user === null) {
            throw new \InvalidArgumentException('Utilisateur introuvable.');
        }

        // Compte verrouillé : refusé même avec le bon mot de passe, comme à la connexion.
        if ($user->isLocked($now)) {
            throw new UserValidationException(['currentPassword' => 'Compte temporairement verrouillé. Réessayez plus tard.']);
        }

        // Un mot de passe actuel faux compte comme une tentative de connexion échouée.
        if (!$this->passwordHasher->verify($currentPassword, $user->passwordHash())) {
            $this->userRepository->save($user->withFailedLoginAttempt(AuthService::MAX_FAILED_ATTEMPTS, $now, AuthService::LOCK_DURATION));

            throw new UserValidationException(['currentPassword' => 'Mot de passe actuel incorrect.']);
        }

        $errors = [];
        $violation = $this->passwordPolicy->violation($newPassword);
        if ($violation !== null) {
            $errors['password'] = $violation;
        } elseif ($newPassword === $currentPassword) {
            $errors['password'] = "Le nouveau mot de passe doit être différent de l'actuel.";
        }
        if ($confirmation !== $newPassword) {
            $errors['passwordConfirmation'] = 'La confirmation ne correspond pas au nouveau mot de passe.';
        }
        if ($errors !== []) {
            throw new UserValidationException($errors);
        }

        $updated = $this->userRepository->save($user->withPasswordHash($this->passwordHasher->hash($newPassword)));
        $this->accountSecurity->sendPasswordChangedAlert($updated, $now);

        return $updated;
    }
}
