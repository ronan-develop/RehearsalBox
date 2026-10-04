<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Entity\ConversationSummary;

interface ConversationRepositoryInterface
{
    public const BOX_RECEIVED = 'received';
    public const BOX_SENT = 'sent';
    public const BOX_ARCHIVED = 'archived';

    public function create(int $initiatorGroupId, int $targetGroupId, string $subject, \DateTimeImmutable $now): Conversation;

    public function addMessage(int $conversationId, int $authorId, string $body, \DateTimeImmutable $now): ConversationMessage;

    public function findById(int $id): ?Conversation;

    /** @return list<ConversationMessage> du plus ancien au plus récent */
    public function messagesOf(int $conversationId): array;

    /**
     * Conversations visibles par la personne (membre de l'un des deux groupes), classées par boîte :
     * - received : non archivées par elle ;
     * - sent : non archivées par elle, où elle a écrit ;
     * - archived : archivées par elle.
     *
     * @return list<ConversationSummary> la plus récemment active d'abord
     */
    public function listFor(int $userId, string $box): array;

    public function markRead(int $conversationId, int $userId, \DateTimeImmutable $now): void;

    public function setArchived(int $conversationId, int $userId, bool $archived): void;

    public function countMessagesBySince(int $authorId, \DateTimeImmutable $since): int;

    public function countUnreadFor(int $userId): int;
}
