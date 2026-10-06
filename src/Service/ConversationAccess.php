<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Conversation;
use App\Repository\Contract\ConversationGuestRepositoryInterface;
use App\Repository\Contract\ConversationRepositoryInterface;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Security\Exception\AccessDeniedException;

/**
 * Qui a accès à une conversation (IDOR) : une seule règle, partagée par tous les services de la messagerie.
 * Participant = membre de l'un des deux groupes ou invité, conversation hors corbeille. Une conversation inconnue,
 * interdite ou à la corbeille produit le même refus : rien ne révèle son existence.
 */
final class ConversationAccess
{
    public const DENIED = 'Accès refusé.';

    public function __construct(
        private readonly ConversationRepositoryInterface $conversations,
        private readonly GroupRepositoryInterface $groups,
        private readonly ?ConversationGuestRepositoryInterface $guests = null,
    ) {
    }

    /** @throws AccessDeniedException */
    public function participant(int $userId, int $conversationId): Conversation
    {
        $conversation = $this->conversations->findById($conversationId);
        if ($conversation === null
            || $conversation->deletedAt() !== null
            || (!$this->groups->isMember($conversation->initiatorGroupId(), $userId)
                && !$this->groups->isMember($conversation->targetGroupId(), $userId)
                && !($this->guests?->isGuest($conversationId, $userId) ?? false))) {
            throw new AccessDeniedException(self::DENIED);
        }

        return $conversation;
    }

    /**
     * Conversation ouverte par cette personne, en service ou à la corbeille, TANT QU'elle appartient encore à l'un des deux
     * groupes : en les quittant, elle perd aussi le droit de la supprimer. @throws AccessDeniedException
     */
    public function ownedBy(int $userId, int $conversationId): Conversation
    {
        $conversation = $this->conversations->findById($conversationId);
        if ($conversation === null
            || $conversation->createdBy() !== $userId
            || (!$this->groups->isMember($conversation->initiatorGroupId(), $userId)
                && !$this->groups->isMember($conversation->targetGroupId(), $userId))) {
            throw new AccessDeniedException(self::DENIED);
        }

        return $conversation;
    }
}
