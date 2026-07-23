<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\Weekday;

final class RecurringSlot
{
    public function __construct(
        private readonly int $id,
        private readonly int $groupId,
        private readonly Weekday $weekday,
        private readonly string $startTime,
        private readonly string $endTime,
        private readonly bool $isActive,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function groupId(): int
    {
        return $this->groupId;
    }

    public function weekday(): Weekday
    {
        return $this->weekday;
    }

    public function startTime(): string
    {
        return $this->startTime;
    }

    public function endTime(): string
    {
        return $this->endTime;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function overlaps(string $startTime, string $endTime): bool
    {
        return $startTime < $this->endTime && $this->startTime < $endTime;
    }
}
