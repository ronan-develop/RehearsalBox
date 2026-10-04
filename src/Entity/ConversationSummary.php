<?php

declare(strict_types=1);

namespace App\Entity;

/** Ligne de liste d'une boîte (Reçues / Envoyées / Archivées) pour une personne donnée. */
final class ConversationSummary
{
    public function __construct(
        private readonly Conversation $conversation,
        private readonly string $initiatorGroupName,
        private readonly string $targetGroupName,
        private readonly ConversationMessage $lastMessage,
        private readonly ?ConversationMessage $myLastMessage,
        private readonly bool $unread,
    ) {
    }

    public function conversation(): Conversation
    {
        return $this->conversation;
    }

    /** Label d'identification partout dans l'interface. */
    public function label(): string
    {
        return $this->initiatorGroupName . ' ↔ ' . $this->targetGroupName;
    }

    public function lastMessage(): ConversationMessage
    {
        return $this->lastMessage;
    }

    /** Dernier message écrit par la personne connectée, null si elle n'a jamais écrit dans ce fil. */
    public function myLastMessage(): ?ConversationMessage
    {
        return $this->myLastMessage;
    }

    public function isUnread(): bool
    {
        return $this->unread;
    }
}
