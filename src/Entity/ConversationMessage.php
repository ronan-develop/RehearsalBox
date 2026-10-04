<?php

declare(strict_types=1);

namespace App\Entity;

final class ConversationMessage
{
    public function __construct(
        private readonly int $id,
        private readonly int $conversationId,
        private readonly int $authorId,
        private readonly string $authorName,
        private readonly string $body,
        private readonly \DateTimeImmutable $createdAt,
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

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
