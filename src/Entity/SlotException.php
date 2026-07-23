<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\SlotExceptionStatus;

final class SlotException
{
    public function __construct(
        private readonly int $id,
        private readonly int $recurringSlotId,
        private readonly \DateTimeImmutable $occurrenceDate,
        private readonly SlotExceptionStatus $status,
        private readonly int $releasedByUserId,
        private readonly ?string $releasedReason,
        private readonly ?int $claimedByGroupId,
        private readonly ?int $claimedByUserId,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function recurringSlotId(): int
    {
        return $this->recurringSlotId;
    }

    public function occurrenceDate(): \DateTimeImmutable
    {
        return $this->occurrenceDate;
    }

    public function status(): SlotExceptionStatus
    {
        return $this->status;
    }

    public function releasedByUserId(): int
    {
        return $this->releasedByUserId;
    }

    public function releasedReason(): ?string
    {
        return $this->releasedReason;
    }

    public function claimedByGroupId(): ?int
    {
        return $this->claimedByGroupId;
    }

    public function claimedByUserId(): ?int
    {
        return $this->claimedByUserId;
    }

    public function isLiberee(): bool
    {
        return $this->status === SlotExceptionStatus::Liberee;
    }
}
