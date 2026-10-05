<?php

declare(strict_types=1);

namespace App\Repository\Contract;

/**
 * Mémoire des e-mails envoyés au contact d'un groupe pour une conversation (#180) : un e-mail immédiat à la création, puis au
 * plus un rappel par message resté sans lecture. Aucune donnée personnelle : seulement (conversation, groupe, dates).
 */
interface ConversationNoticeRepositoryInterface
{
    /**
     * Réserve l'e-mail immédiat de ce groupe pour cette conversation. true si la réservation est nouvelle (on peut
     * envoyer), false si ce groupe a déjà été prévenu : atomique, deux appels concurrents n'envoient qu'un e-mail.
     */
    public function claimInitial(int $conversationId, int $groupId, \DateTimeImmutable $now): bool;

    /** Annule la réservation (envoi échoué) pour qu'un nouvel essai reste possible. */
    public function releaseInitial(int $conversationId, int $groupId): void;

    public function initialNotifiedAt(int $conversationId, int $groupId): ?\DateTimeImmutable;
}
