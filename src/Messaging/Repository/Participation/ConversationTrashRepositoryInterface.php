<?php

declare(strict_types=1);

namespace App\Messaging\Repository\Participation;

use App\Messaging\Entity\ConversationSummary;

/** Corbeille des conversations : mise à la corbeille, restauration, suppression définitive et purge (30 jours). */
interface ConversationTrashRepositoryInterface
{
    /** Met la conversation à la corbeille : elle disparaît des listes et des compteurs des deux groupes. */
    public function moveToTrash(int $conversationId, \DateTimeImmutable $now): void;

    public function restore(int $conversationId): void;

    /** Suppression définitive (messages, lectures et avis d'envoi partent avec elle). */
    public function delete(int $conversationId): void;

    /**
     * Corbeille de la personne : ses conversations mises à la corbeille à partir de $trashedSince.
     *
     * @return list<ConversationSummary> la plus récemment supprimée d'abord
     */
    public function listTrashedBy(int $userId, \DateTimeImmutable $trashedSince): array;

    /** Supprime pour de bon les conversations à la corbeille avant $cutoff ; renvoie leur nombre. */
    public function purgeTrashedBefore(\DateTimeImmutable $cutoff): int;
}
