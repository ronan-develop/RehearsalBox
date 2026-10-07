<?php

declare(strict_types=1);

namespace App\Messaging\Repository;

use App\Messaging\Entity\Conversation;
use App\Messaging\Entity\ConversationMessage;
use App\Messaging\Entity\ConversationSummary;

interface ConversationRepositoryInterface
{
    public const BOX_ACTIVE = 'active';
    public const BOX_ARCHIVED = 'archived';

    public function create(int $initiatorGroupId, int $targetGroupId, ?string $title, \DateTimeImmutable $now, ?int $createdBy = null): Conversation;

    /** @param string|null $title null pour retirer le titre (la conversation reprend le label de ses deux groupes) */
    public function rename(int $conversationId, ?string $title): void;

    public function findById(int $id): ?Conversation;

    /**
     * Conversations visibles par la personne (membre de l'un des deux groupes). L'archivage est DÉRIVÉ :
     * une conversation dont le dernier message est antérieur à $inactiveBefore est archivée, sinon active.
     *
     * @return list<ConversationSummary> la plus récemment active d'abord
     */
    public function listFor(int $userId, string $box, \DateTimeImmutable $inactiveBefore): array;

    /** @param string|null $box null = toutes les conversations (actives et archivées) */
    public function countUnreadFor(int $userId, \DateTimeImmutable $inactiveBefore, ?string $box = null): int;

    /** Nombre de personnes qui participent à la conversation : membres des deux groupes et invités. */
    public function participantCount(int $conversationId): int;

}
