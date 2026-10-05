<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Entity\ConversationThread;
use App\Entity\Group;
use App\Entity\SeenReceipt;
use App\Repository\Contract\ConversationMessageRepositoryInterface;
use App\Repository\Contract\ConversationPresenceRepositoryInterface;
use App\Repository\Contract\ConversationRepositoryInterface;
use App\Repository\Contract\GroupRepositoryInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Construit le fil d'une conversation vu par une personne : messages (tous ou à partir d'une ancre), qui écrit, « vu par »,
 * premier message non lu, pastille de groupe de chaque auteur et mentions. L'accès est vérifié par l'appelant.
 */
final class ConversationThreadBuilder
{
    private const TYPING_WINDOW = '-5 seconds';

    public function __construct(
        private readonly ConversationRepositoryInterface $conversations,
        private readonly ConversationMessageRepositoryInterface $messages,
        private readonly ConversationPresenceRepositoryInterface $presence,
        private readonly GroupRepositoryInterface $groups,
        private readonly ClockInterface $clock,
        private readonly ?ConversationMentionService $mentions = null,
    ) {
    }

    /** @param int $afterId 0 = le fil complet ; sinon seulement les messages suivants (polling) */
    public function build(Conversation $conversation, int $userId, int $afterId, ?\DateTimeImmutable $lastRead = null): ConversationThread
    {
        $messages = $this->messages->messagesOf($conversation->id(), $afterId);
        $firstUnreadId = $afterId === 0 ? $this->firstUnreadId($messages, $userId, $lastRead) : null;

        return new ConversationThread(
            $conversation,
            $this->labelOf($conversation),
            $messages,
            $this->presence->typingNames($conversation->id(), $userId, $this->clock->now()->modify(self::TYPING_WINDOW)),
            $this->seenReceipt($conversation, $userId),
            $this->authorGroups($conversation, $messages),
            $firstUnreadId,
            $afterId > 0 ? $this->messages->messageById($conversation->id(), $afterId) : null,
            $this->mentions?->forMessages($messages) ?? [],
        );
    }

    /** @param list<ConversationMessage> $messages */
    private function firstUnreadId(array $messages, int $userId, ?\DateTimeImmutable $lastRead): ?int
    {
        foreach ($messages as $message) {
            if ($message->authorId() !== $userId && ($lastRead === null || $message->createdAt() > $lastRead)) {
                return $message->id();
            }
        }

        return null;
    }

    private function seenReceipt(Conversation $conversation, int $userId): ?SeenReceipt
    {
        $mine = $this->messages->lastMessageBy($conversation->id(), $userId);
        if ($mine === null) {
            return null;
        }

        return new SeenReceipt(
            $mine->id(),
            $this->presence->readersOf($conversation->id(), $mine->createdAt(), $userId),
            max(0, $this->conversations->participantCount($conversation->id()) - 1),
        );
    }

    /**
     * Pastille : groupe d'appartenance de chaque auteur parmi les deux groupes de la conversation ;
     * null s'il est dans les deux (ou plus dans aucun) : on ne devine pas.
     *
     * @param list<ConversationMessage> $messages
     *
     * @return array<int, Group|null>
     */
    private function authorGroups(Conversation $conversation, array $messages): array
    {
        $initiator = $this->groups->findById($conversation->initiatorGroupId());
        $target = $this->groups->findById($conversation->targetGroupId());

        $result = [];
        foreach ($messages as $message) {
            $authorId = $message->authorId();
            if (array_key_exists($authorId, $result)) {
                continue;
            }
            $inInitiator = $initiator !== null && $this->groups->isMember($initiator->id(), $authorId);
            $inTarget = $target !== null && $this->groups->isMember($target->id(), $authorId);
            $result[$authorId] = $inInitiator === $inTarget ? null : ($inInitiator ? $initiator : $target);
        }

        return $result;
    }

    private function labelOf(Conversation $conversation): string
    {
        $initiator = $this->groups->findById($conversation->initiatorGroupId());
        $target = $this->groups->findById($conversation->targetGroupId());

        return ($initiator?->name() ?? '?') . ' ↔ ' . ($target?->name() ?? '?');
    }
}
