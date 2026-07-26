<?php

declare(strict_types=1);

namespace App\Entity;

final class RequestableSlot
{
    public function __construct(
        private readonly RecurringSlot $slot,
        private readonly string $groupName,
        private readonly int $groupId,
    ) {
    }

    public function slot(): RecurringSlot
    {
        return $this->slot;
    }

    public function groupName(): string
    {
        return $this->groupName;
    }

    public function groupId(): int
    {
        return $this->groupId;
    }
}
