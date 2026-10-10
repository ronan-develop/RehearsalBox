<?php

declare(strict_types=1);

namespace App\Messaging\Service\Mention;

use App\Messaging\Entity\Conversation;
use App\Messaging\Entity\MentionPlan;
use App\Messaging\Service\Mention\ConversationMentionsInterface;

/** Null Object : aucune mention (le texte n'est jamais analysé, personne n'est invité ni prévenu). Évite un collaborateur « optionnel » à tester partout. */
final class NoConversationMentions implements ConversationMentionsInterface
{
    public function plan(int $actorId, ?int $initiatorGroupId, ?int $targetGroupId, ?int $conversationId, string $body, array $userIds): MentionPlan
    {
        return new MentionPlan([], []);
    }

    public function planEdit(int $actorId, Conversation $conversation, int $messageId, string $body, array $newIds): MentionPlan
    {
        return new MentionPlan([], []);
    }

    public function replace(MentionPlan $plan, int $messageId): void
    {
    }

    public function addGuests(MentionPlan $plan, int $actorId, int $conversationId, \DateTimeImmutable $now): void
    {
    }

    public function notify(MentionPlan $plan, Conversation $conversation, int $authorId, string $authorName, \DateTimeImmutable $now): void
    {
    }

    public function record(MentionPlan $plan, int $messageId): void
    {
    }

    public function forMessages(array $messages): array
    {
        return [];
    }
}
