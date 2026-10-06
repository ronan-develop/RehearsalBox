<?php

declare(strict_types=1);

namespace App\Presenter;

use App\Entity\Enum\Weekday;
use App\Entity\RequestableSlot;

/** Un jour de la semaine du planning avec ses créneaux (liste mobile, #201). */
final class PlanningDay
{
    /** @param list<RequestableSlot> $slots */
    public function __construct(
        private readonly Weekday $weekday,
        private readonly bool $isToday,
        private readonly array $slots,
    ) {
    }

    public function weekday(): Weekday
    {
        return $this->weekday;
    }

    public function isToday(): bool
    {
        return $this->isToday;
    }

    /** @return list<RequestableSlot> */
    public function slots(): array
    {
        return $this->slots;
    }
}
