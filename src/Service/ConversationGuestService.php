<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\TransactionRunner;
use App\Repository\Contract\ConversationGuestRepositoryInterface;
use App\Repository\Contract\ConversationRepositoryInterface;
use App\Repository\Contract\UserRepositoryInterface;
use App\Security\Exception\AccessDeniedException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Retrait d'un invité (#178). Peuvent le retirer : la personne qui l'a ajouté, l'initiateur de la conversation, ou
 * l'invité lui-même (il quitte). Une ligne du fil l'annonce. L'ajout d'invités se fait par les mentions
 * (ConversationMentionService).
 */
final class ConversationGuestService
{
    public function __construct(
        private readonly ConversationAccess $access,
        private readonly ConversationGuestRepositoryInterface $guests,
        private readonly ConversationRepositoryInterface $conversations,
        private readonly UserRepositoryInterface $users,
        private readonly TransactionRunner $transactions,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @throws AccessDeniedException pas participant, pas invité, ou pas le droit de le retirer */
    public function remove(int $userId, int $conversationId, int $guestId): void
    {
        $conversation = $this->access->participant($userId, $conversationId);
        if (!$this->guests->isGuest($conversationId, $guestId)
            || ($userId !== $guestId && $userId !== $conversation->createdBy() && $userId !== $this->guests->addedBy($conversationId, $guestId))) {
            throw new AccessDeniedException(ConversationAccess::DENIED);
        }

        $name = $this->users->findById($guestId)?->displayName() ?? 'une personne';
        $line = $userId === $guestId ? 'a quitté la conversation' : "a retiré {$name} de la conversation";
        $now = $this->clock->now();
        $this->transactions->run(function () use ($conversationId, $guestId, $userId, $line, $now): void {
            $this->guests->remove($conversationId, $guestId);
            $this->conversations->addMessage($conversationId, $userId, $line, $now, true);
        });
    }
}
