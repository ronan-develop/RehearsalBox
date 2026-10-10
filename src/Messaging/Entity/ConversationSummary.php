<?php

declare(strict_types=1);

namespace App\Messaging\Entity;

/** Ligne de la liste « Conversations » ou « Archivées » pour une personne donnée. */
final class ConversationSummary
{
    public function __construct(
        private readonly Conversation $conversation,
        private readonly string $label,
        private readonly ConversationMessage $lastMessage,
        private readonly bool $unread,
        private readonly bool $mentioned = false,
        private readonly bool $muted = false,
    ) {
    }

    public function conversation(): Conversation
    {
        return $this->conversation;
    }

    /** Identifie la conversation : ses deux groupes, ou l'autre personne pour un message direct. */
    public function label(): string
    {
        return $this->label;
    }

    /** Titre affiché : celui choisi par les membres, sinon son label. */
    public function displayTitle(): string
    {
        return $this->conversation->title() ?? $this->label();
    }

    public function lastMessage(): ConversationMessage
    {
        return $this->lastMessage;
    }

    public function isUnread(): bool
    {
        return $this->unread;
    }

    /** La personne est mentionnée dans un message qu'elle n'a pas encore lu. */
    /** Sourdine de la personne qui consulte la liste (#210). */
    public function isMuted(): bool
    {
        return $this->muted;
    }

    public function isMentioned(): bool
    {
        return $this->mentioned;
    }
}
