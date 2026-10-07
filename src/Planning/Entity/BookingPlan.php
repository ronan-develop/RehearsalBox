<?php

declare(strict_types=1);

namespace App\Planning\Entity;

/** Le plan d'une réservation voulue (#263) : les parties libres à réserver et ce qui chevauche d'autres groupes. */
final class BookingPlan
{
    /**
     * @param list<TimeRange>   $freeParts
     * @param list<PlanConflict> $conflicts dans l'ordre de la journée
     */
    public function __construct(private readonly array $freeParts, private readonly array $conflicts)
    {
    }

    /** @return list<TimeRange> les plages que le groupe peut réserver telles quelles */
    public function freeParts(): array
    {
        return $this->freeParts;
    }

    /** @return list<PlanConflict> */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    public function isFullyFree(): bool
    {
        return $this->conflicts === [];
    }
}
