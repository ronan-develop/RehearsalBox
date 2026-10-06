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
        private readonly Requester $requester,
        private readonly ?string $requestReason,
        private readonly ?int $respondedByUserId,
        private readonly \DateTimeImmutable $createdAt,
        private readonly ?TimeRange $range = null,
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

    public function requester(): Requester
    {
        return $this->requester;
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

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Plage demandée (#263), null = tout le créneau du titulaire ; le reste du créneau reste au titulaire. */
    public function range(): ?TimeRange
    {
        return $this->range;
    }
}
