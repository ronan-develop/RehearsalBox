<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ExceptionDirection;

final class DashboardExceptionItem
{
    public function __construct(
        private readonly SlotException $exception,
        private readonly ExceptionDirection $direction,
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
}
