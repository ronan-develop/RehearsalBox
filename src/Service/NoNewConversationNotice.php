<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Conversation;
use App\Entity\Group;
use App\Service\Contract\NewConversationNotifierInterface;

/** Null Object : aucun e-mail de nouvelle conversation. */
final class NoNewConversationNotice implements NewConversationNotifierInterface
{
    public function newConversation(Conversation $conversation, string $authorName, string $authorGroupName, Group $targetGroup, \DateTimeImmutable $now): void
    {
    }
}
