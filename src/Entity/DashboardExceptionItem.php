<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ExceptionDirection;

final class DashboardExceptionItem
{
    public function __construct(
        private readonly SlotException $exception,
        private readonly ExceptionDirection $direction,
        private readonly string $requestedByGroupName,
        private readonly ?string $requestedByGroupColorHex = null,
        private readonly ?RecurringSlot $slot = null,
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
}
