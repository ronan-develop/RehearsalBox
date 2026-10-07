<?php

declare(strict_types=1);

namespace App\Messaging\Entity;

final class ConversationMessage
{
    public function __construct(
        private readonly int $id,
        private readonly int $conversationId,
        private readonly int $authorId,
        private readonly string $authorName,
        private readonly string $body,
        private readonly \DateTimeImmutable $createdAt,
        private readonly bool $system = false,
        private readonly ?\DateTimeImmutable $editedAt = null,
        private readonly ?MessageQuote $quote = null,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function conversationId(): int
    {
        return $this->conversationId;
    }

    public function authorId(): int
    {
        return $this->authorId;
    }

    public function authorName(): string
    {
        return $this->authorName;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** Ligne générée par l'application (ex. renommage), affichée au centre du fil et non comme un message. */
    public function isSystem(): bool
    {
        return $this->system;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Date de la dernière modification par son auteur ; null si le message n'a jamais été modifié. */
    public function editedAt(): ?\DateTimeImmutable
    {
        return $this->editedAt;
    }

    /** Message cité par celui-ci (#214) ; null s'il n'en cite aucun. */
    public function quote(): ?MessageQuote
    {
        return $this->quote;
    }
}
