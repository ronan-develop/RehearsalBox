<?php

declare(strict_types=1);

namespace App\Entity;

/** Échange entre deux groupes : le groupe qui l'ouvre (initiateur) et celui qui est visé. */
final class Conversation
{
    public function __construct(
        private readonly int $id,
        private readonly int $initiatorGroupId,
        private readonly int $targetGroupId,
        private readonly string $subject,
        private readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function initiatorGroupId(): int
    {
        return $this->initiatorGroupId;
    }

    public function targetGroupId(): int
    {
        return $this->targetGroupId;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function involvesGroup(int $groupId): bool
    {
        return $groupId === $this->initiatorGroupId || $groupId === $this->targetGroupId;
    }
}
