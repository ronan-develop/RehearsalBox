<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\Contract\NotificationPreferenceRepositoryInterface;
use App\Repository\Contract\UserRepositoryInterface;
use App\Security\DisplayNamePolicy;
use App\Service\Exception\UserNotFoundException;
use App\Service\Exception\UserValidationException;

/**
 * Informations modifiables par l'utilisateur lui-même (#161). L'identifiant du compte vient
 * toujours de la session (jamais de la requête) : voir AccountApiController.
 */
final class ProfileService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly NotificationPreferenceRepositoryInterface $preferences,
        private readonly DisplayNamePolicy $displayNamePolicy = new DisplayNamePolicy(),
    ) {
    }

    /**
     * @throws UserValidationException nom refusé par la politique du nom affiché
     * @throws UserNotFoundException
     */
    public function updateDisplayName(int $userId, string $displayName): User
    {
        $displayName = $this->displayNamePolicy->normalize($displayName);

        $violation = $this->displayNamePolicy->violation($displayName);
        if ($violation !== null) {
            throw new UserValidationException(['displayName' => $violation]);
        }

        $user = $this->userRepository->findById($userId) ?? throw new UserNotFoundException("Utilisateur {$userId} introuvable.");

        return $this->userRepository->save($user->withDisplayName($displayName));
    }

    /**
     * Recevoir ou non les e-mails de mention (#178). Ne touche qu'à la préférence de la personne désignée (celle de la session).
     *
     * @throws UserNotFoundException
     */
    public function updateEmailNotifications(int $userId, bool $enabled): void
    {
        $this->userRepository->findById($userId) ?? throw new UserNotFoundException("Utilisateur {$userId} introuvable.");
        $this->preferences->setEmailEnabled($userId, $enabled);
    }
}
