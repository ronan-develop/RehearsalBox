<?php

declare(strict_types=1);

namespace App\Account\Repository;

/**
 * Demandes de changement d'adresse e-mail (#164). Le jeton n'est jamais stocké en clair :
 * seule son empreinte (sha256) l'est.
 */
interface EmailChangeRepositoryInterface
{
    public function create(int $userId, string $newEmail, string $tokenHash, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): void;

    /** Annule les demandes en attente de cet utilisateur (une nouvelle demande remplace les précédentes). */
    public function invalidateAllForUser(int $userId, \DateTimeImmutable $now): void;

    public function countCreatedSince(int $userId, \DateTimeImmutable $since): int;

    /**
     * Consomme le jeton de façon atomique (usage unique).
     *
     * @return array{userId: int, newEmail: string}|null null si le jeton est inconnu, expiré ou déjà utilisé
     */
    public function consume(string $tokenHash, \DateTimeImmutable $now): ?array;
}
