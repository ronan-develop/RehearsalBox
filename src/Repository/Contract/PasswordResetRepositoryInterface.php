<?php

declare(strict_types=1);

namespace App\Repository\Contract;

interface PasswordResetRepositoryInterface
{
    /** Enregistre un jeton (empreinte SHA-256, jamais le jeton en clair). */
    public function create(int $userId, string $tokenHash, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): void;

    /** Annule les jetons non utilisés d'un utilisateur. */
    public function invalidateAllForUser(int $userId, \DateTimeImmutable $now): void;

    /** Nombre de demandes créées depuis la date donnée pour cet utilisateur. */
    public function countCreatedSince(int $userId, \DateTimeImmutable $since): int;

    /**
     * Consomme atomiquement un jeton valide (non utilisé, non expiré).
     *
     * @return int|null id de l'utilisateur, ou null si le jeton est inconnu, expiré ou déjà utilisé
     */
    public function consume(string $tokenHash, \DateTimeImmutable $now): ?int;
}
