<?php

declare(strict_types=1);

namespace App\Repository\Contract;

interface PasswordResetRepositoryInterface
{
    /** Réinitialisation du mot de passe par e-mail (#107). */
    public const PURPOSE_RESET = 'reset';

    /** Alerte « Ce n'est pas moi » après un changement de mot de passe (#108). */
    public const PURPOSE_ALERT = 'alert';

    /** Enregistre un jeton (empreinte SHA-256, jamais le jeton en clair). */
    public function create(int $userId, string $tokenHash, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now, string $purpose = self::PURPOSE_RESET): void;

    /** Annule les jetons non utilisés d'un utilisateur, pour une finalité donnée. */
    public function invalidateAllForUser(int $userId, \DateTimeImmutable $now, string $purpose = self::PURPOSE_RESET): void;

    /** Nombre de jetons créés depuis la date donnée pour cet utilisateur et cette finalité. */
    public function countCreatedSince(int $userId, \DateTimeImmutable $since, string $purpose = self::PURPOSE_RESET): int;

    /**
     * Consomme atomiquement un jeton valide (non utilisé, non expiré) de la finalité donnée.
     *
     * @return int|null id de l'utilisateur, ou null si le jeton est inconnu, expiré ou déjà utilisé
     */
    public function consume(string $tokenHash, \DateTimeImmutable $now, string $purpose = self::PURPOSE_RESET): ?int;
}
