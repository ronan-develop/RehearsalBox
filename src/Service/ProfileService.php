<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\Contract\UserRepositoryInterface;
use App\Service\Exception\UserNotFoundException;
use App\Service\Exception\UserValidationException;

/**
 * Informations modifiables par l'utilisateur lui-même (#161). L'identifiant du compte vient
 * toujours de la session (jamais de la requête) : voir AccountApiController.
 */
final class ProfileService
{
    private const DISPLAY_NAME_MAX_LENGTH = 100;

    public function __construct(private readonly UserRepositoryInterface $userRepository)
    {
    }

    /**
     * @throws UserValidationException nom vide, trop long ou contenant un caractère de contrôle
     * @throws UserNotFoundException
     */
    public function updateDisplayName(int $userId, string $displayName): User
    {
        $displayName = trim($displayName);

        if ($displayName === '') {
            throw new UserValidationException(['displayName' => 'Le nom affiché est requis.']);
        }
        if (mb_strlen($displayName) > self::DISPLAY_NAME_MAX_LENGTH) {
            throw new UserValidationException(['displayName' => 'Le nom affiché ne doit pas dépasser ' . self::DISPLAY_NAME_MAX_LENGTH . ' caractères.']);
        }
        // Caractères de contrôle et de mise en forme (saut de ligne, ESC, inversion de sens du texte…) :
        // le nom est affiché partout (en-tête, listes, e-mails).
        if (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $displayName) === 1) {
            throw new UserValidationException(['displayName' => 'Le nom affiché contient un caractère non autorisé.']);
        }

        $user = $this->userRepository->findById($userId) ?? throw new UserNotFoundException("Utilisateur {$userId} introuvable.");

        return $this->userRepository->save($user->withDisplayName($displayName));
    }
}
