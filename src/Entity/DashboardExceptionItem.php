<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ExceptionDirection;
use App\Entity\Enum\RequestKind;

final class DashboardExceptionItem implements DashboardRequestItem
{
    public function __construct(
        private readonly SlotException $exception,
        private readonly ExceptionDirection $direction,
        private readonly string $requestedByGroupName,
        private readonly ?string $requestedByGroupColorHex = null,
        private readonly ?RecurringSlot $slot = null,
        private readonly ?string $holderGroupName = null,
    ) {
    }

    public function exception(): SlotException
    {
        return $this->exception;
    }

    public function direction(): ExceptionDirection
    {
        return $this->direction;
    }

    public function requestedByGroupName(): string
    {
        return $this->requestedByGroupName;
    }

    public function requestedByGroupColorHex(): ?string
    {
        return $this->requestedByGroupColorHex;
    }

    public function slot(): ?RecurringSlot
    {
        return $this->slot;
    }

    /** Le groupe titulaire du créneau, qui valide l'échange (#292). */
    public function holderGroupName(): ?string
    {
        return $this->holderGroupName;
    }

    public function kind(): RequestKind
    {
        return RequestKind::Echange;
    }

    public function requestId(): int
    {
        return $this->exception->id();
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->exception->createdAt();
    }
}
