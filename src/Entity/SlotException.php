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
        private readonly int $requestedByGroupId,
        private readonly int $requestedByUserId,
        private readonly ?string $requestReason,
        private readonly ?int $respondedByUserId,
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

    public function requestedByGroupId(): int
    {
        return $this->requestedByGroupId;
    }

    public function requestedByUserId(): int
    {
        return $this->requestedByUserId;
    }

    public function requestReason(): ?string
    {
        return $this->requestReason;
    }

    public function respondedByUserId(): ?int
    {
        return $this->respondedByUserId;
    }

    public function isEnAttente(): bool
    {
        return $this->status === SlotExceptionStatus::EnAttente;
    }
}
