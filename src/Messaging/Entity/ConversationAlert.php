<?php

declare(strict_types=1);

namespace App\Messaging\Entity;

/** Avis dans l'application : une conversation a été mise à la corbeille ou restaurée. Ne contient jamais le titre. */
final class ConversationAlert
{
    public const DELETED = 'deleted';
    public const RESTORED = 'restored';

    public function __construct(
        private readonly int $id,
        private readonly ?int $conversationId,
        private readonly string $kind,
        private readonly string $label,
        private readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    /** Null une fois la conversation supprimée pour de bon. */
    public function conversationId(): ?int
    {
        return $this->conversationId;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /** « Groupe A ↔ Groupe B ». */
    public function label(): string
    {
        return $this->label;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
