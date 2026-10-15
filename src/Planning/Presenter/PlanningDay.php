<?php

declare(strict_types=1);

namespace App\Planning\Presenter;

use App\Planning\Entity\Weekday;
use App\Planning\Entity\RequestableSlot;

/** Un jour de la semaine du planning avec ses créneaux, fixes et occasionnels (liste mobile, #201, #461). */
final class PlanningDay
{
    /**
     * @param list<RequestableSlot>             $slots
     * @param array<int, list<SlotCession>>     $cessions cessions de la semaine, par identifiant du créneau fixe cédé
     */
    public function __construct(
        private readonly Weekday $weekday,
        private readonly bool $isToday,
        private readonly array $slots,
        private readonly array $cessions = [],
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

    /** @return list<SlotCession> ce que le groupe titulaire de ce créneau fixe a cédé ce jour-là (vide : rien) */
    public function cessionsFor(RequestableSlot $slot): array
    {
        return $this->cessions[$slot->slot()->id()] ?? [];
    }
}
