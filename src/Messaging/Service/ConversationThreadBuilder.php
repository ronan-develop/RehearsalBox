<?php

declare(strict_types=1);

namespace App\Messaging\Service;

use App\Messaging\Entity\Conversation;
use App\Messaging\Entity\ConversationMessage;
use App\Messaging\Entity\ConversationThread;
use App\Group\Entity\Group;
use App\Messaging\Entity\SeenReceipt;
use App\Messaging\Repository\ConversationMessageRepositoryInterface;
use App\Messaging\Repository\ConversationPresenceRepositoryInterface;
use App\Messaging\Repository\ConversationRepositoryInterface;
use App\Group\Repository\GroupRepositoryInterface;
use App\Account\Repository\UserRepositoryInterface;
use App\Messaging\Service\Mention\ConversationMentionsInterface;
use Symfony\Component\Clock\ClockInterface;
use App\Messaging\Service\Mention\NoConversationMentions;

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
        private readonly UserRepositoryInterface $users,
        private readonly ClockInterface $clock,
        private readonly ConversationMentionsInterface $mentions = new NoConversationMentions(),
    ) {
    }

    /** @param int $afterId 0 = le fil complet ; sinon seulement les messages suivants (polling) */
    public function build(Conversation $conversation, int $userId, int $afterId, ?\DateTimeImmutable $lastRead = null): ConversationThread
    {
        $messages = $this->messages->messagesOf($conversation->id(), $afterId);
        $firstUnreadId = $afterId === 0 ? $this->firstUnreadId($messages, $userId, $lastRead) : null;

        return new ConversationThread(
            $conversation,
            $this->labelOf($conversation, $userId),
            $messages,
            $this->presence->typingNames($conversation->id(), $userId, $this->clock->now()->modify(self::TYPING_WINDOW)),
            $this->seenReceipt($conversation, $userId),
            $this->authorGroups($conversation, $messages),
            $firstUnreadId,
            $afterId > 0 ? $this->messages->messageById($conversation->id(), $afterId) : null,
            $this->mentions->forMessages($messages),
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
     * null s'il est dans les deux (ou plus dans aucun) : on ne devine pas. Un message direct n'a pas de groupe : toujours null.
     *
     * @param list<ConversationMessage> $messages
     *
     * @return array<int, Group|null>
     */
    private function authorGroups(Conversation $conversation, array $messages): array
    {
        if ($conversation->isDirect()) {
            return array_fill_keys(array_map(static fn (ConversationMessage $m): int => $m->authorId(), $messages), null);
        }

        $initiator = $this->groups->findById((int) $conversation->initiatorGroupId());
        $target = $this->groups->findById((int) $conversation->targetGroupId());

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

    /** Les deux groupes, ou le nom de l'autre personne pour un message direct (vu par $userId). */
    private function labelOf(Conversation $conversation, int $userId): string
    {
        if ($conversation->isDirect()) {
            $other = $conversation->otherParticipantOf($userId);

            return ($other === null ? null : $this->users->findById($other)?->displayName()) ?? '?';
        }

        $initiator = $this->groups->findById((int) $conversation->initiatorGroupId());
        $target = $this->groups->findById((int) $conversation->targetGroupId());

        return ($initiator?->name() ?? '?') . ' ↔ ' . ($target?->name() ?? '?');
    }
}
