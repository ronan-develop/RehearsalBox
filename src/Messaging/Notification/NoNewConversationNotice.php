<?php

declare(strict_types=1);

namespace App\Messaging\Notification;

use App\Messaging\Entity\Conversation;
use App\Group\Entity\Group;
use App\Messaging\Notification\NewConversationNotifierInterface;

/** Null Object : aucun e-mail de nouvelle conversation. */
final class NoNewConversationNotice implements NewConversationNotifierInterface
{
    public function newConversation(Conversation $conversation, string $authorName, string $authorGroupName, Group $targetGroup, \DateTimeImmutable $now): void
    {
    }
}
