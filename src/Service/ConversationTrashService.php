<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\TransactionRunner;
use App\Entity\Conversation;
use App\Entity\ConversationAlert;
use App\Entity\ConversationSummary;
use App\Repository\Contract\ConversationAlertRepositoryInterface;
use App\Repository\Contract\ConversationRepositoryInterface;
use App\Security\Exception\AccessDeniedException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Corbeille des conversations et avis aux participants (#190). Seule la personne qui a ouvert la conversation peut la
 * mettre à la corbeille, la restaurer (TRASH_RETENTION) ou la supprimer pour de bon ; les autres participants en sont
 * prévenus par un avis dans l'application, sans le titre.
 */
final class ConversationTrashService
{
    public const TRASH_RETENTION = '-30 days';

    public function __construct(
        private readonly ConversationAccess $access,
        private readonly ConversationRepositoryInterface $conversations,
        private readonly TransactionRunner $transactions,
        private readonly ClockInterface $clock,
        private readonly ?ConversationAlertRepositoryInterface $alerts = null,
    ) {
    }

    /**
     * Met la conversation à la corbeille : elle disparaît chez les deux groupes, les autres participants en sont prévenus.
     *
     * @throws AccessDeniedException pas l'initiateur, conversation inconnue ou déjà à la corbeille
     */
    public function delete(int $userId, int $conversationId): void
    {
        $conversation = $this->access->ownedBy($userId, $conversationId);
        if ($conversation->deletedAt() !== null) {
            throw new AccessDeniedException(ConversationAccess::DENIED);
        }
        $now = $this->clock->now();
        $this->transactions->run(function () use ($conversationId, $userId, $now): void {
            $this->conversations->moveToTrash($conversationId, $now);
            $this->alerts?->notifyParticipants($conversationId, $userId, ConversationAlert::DELETED, $now);
        });
    }

    /** @throws AccessDeniedException pas l'initiateur, pas à la corbeille, ou corbeille expirée */
    public function restore(int $userId, int $conversationId): void
    {
        $conversation = $this->trashedConversation($userId, $conversationId);
        $now = $this->clock->now();
        $this->transactions->run(function () use ($conversation, $userId, $now): void {
            $this->conversations->restore($conversation->id());
            $this->alerts?->notifyParticipants($conversation->id(), $userId, ConversationAlert::RESTORED, $now);
        });
    }

    /** Suppression définitive d'une conversation déjà à la corbeille. @throws AccessDeniedException */
    public function deletePermanently(int $userId, int $conversationId): void
    {
        $this->conversations->delete($this->trashedConversation($userId, $conversationId)->id());
    }

    /** Corbeille de la personne ; les conversations expirées sont purgées au passage (aucune tâche planifiée nécessaire). @return list<ConversationSummary> */
    public function trash(int $userId): array
    {
        $cutoff = $this->cutoff();
        $this->conversations->purgeTrashedBefore($cutoff);

        return $this->conversations->listTrashedBy($userId, $cutoff);
    }

    /** Nombre de conversations dans la corbeille (lecture seule : aucune purge). */
    public function trashCount(int $userId): int
    {
        return count($this->conversations->listTrashedBy($userId, $this->cutoff()));
    }

    /** @return list<ConversationAlert> avis non fermés des 30 derniers jours */
    public function alertsFor(int $userId): array
    {
        return $this->alerts?->findActiveFor($userId, $this->cutoff()) ?? [];
    }

    public function alertCount(int $userId): int
    {
        return $this->alerts?->countActiveFor($userId, $this->cutoff()) ?? 0;
    }

    public function dismissAlert(int $userId, int $alertId): void
    {
        $this->alerts?->dismiss($alertId, $userId, $this->clock->now());
    }

    private function cutoff(): \DateTimeImmutable
    {
        return $this->clock->now()->modify(self::TRASH_RETENTION);
    }

    /** Conversation de cette personne à la corbeille depuis moins de TRASH_RETENTION. @throws AccessDeniedException */
    private function trashedConversation(int $userId, int $conversationId): Conversation
    {
        $conversation = $this->access->ownedBy($userId, $conversationId);
        if ($conversation->deletedAt() === null || $conversation->deletedAt() < $this->cutoff()) {
            throw new AccessDeniedException(ConversationAccess::DENIED);
        }

        return $conversation;
    }
}
