<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BookingPlan;
use App\Entity\PlanConflict;
use App\Entity\TimeRange;
use App\Repository\Contract\FreeSlotBookingRepositoryInterface;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Repository\Contract\RecurringSlotRepositoryInterface;

/**
 * Plan d'une réservation voulue (#263, partie 3b) : « je veux le mercredi de 9h à 19h » devient la partie LIBRE à réserver (ici 9h–18h30)
 * et ce qui chevauche d'autres groupes (ici 18h30–19h chez The Office, sur lequel une demande d'échange partielle est possible).
 * Lecture seule : rien n'est créé. Le calcul des plages est celui de TimeRange (fonctions pures).
 */
final class BookingPlanner
{
    public function __construct(
        private readonly RecurringSlotRepositoryInterface $slots,
        private readonly GroupRepositoryInterface $groups,
        private readonly FreeSlotBookingRepositoryInterface $bookings,
    ) {
    }

    public function plan(int $groupId, \DateTimeImmutable $date, TimeRange $wanted): BookingPlan
    {
        $conflicts = [];

        $weekday = (int) $date->format('N') - 1;
        foreach ($this->slots->findAllActive() as $slot) {
            $overlap = $slot->weekday()->value === $weekday ? $wanted->intersect(new TimeRange($slot->startTime(), $slot->endTime())) : null;
            if ($overlap !== null) {
                $conflicts[] = new PlanConflict(PlanConflict::FIXED, $slot->id(), $this->groupName($slot->groupId()), $slot->groupId() === $groupId, $overlap);
            }
        }
        foreach ($this->bookings->findOverlapping($date, $wanted) as $booking) {
            $overlap = $wanted->intersect($booking->range());
            if ($overlap !== null) {
                $conflicts[] = new PlanConflict(PlanConflict::BOOKING, null, $this->groupName($booking->requester()->groupId()), $booking->requester()->groupId() === $groupId, $overlap);
            }
        }

        usort($conflicts, static fn (PlanConflict $a, PlanConflict $b): int => $a->overlap()->start() <=> $b->overlap()->start());

        return new BookingPlan(
            $wanted->subtract(array_map(static fn (PlanConflict $conflict): TimeRange => $conflict->overlap(), $conflicts)),
            $conflicts,
        );
    }

    private function groupName(int $groupId): string
    {
        return $this->groups->findById($groupId)?->name() ?? 'Groupe supprimé';
    }
}
