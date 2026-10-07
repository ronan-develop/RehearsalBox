<?php

declare(strict_types=1);

namespace App\Planning\Presenter;

use App\Planning\Entity\Weekday;
use App\Planning\Entity\RequestableSlot;

/**
 * Regroupe les créneaux fixes par jour, à partir d'AUJOURD'HUI (#201) : la semaine se lit de ce qui arrive en premier. Un jour
 * sans créneau n'apparaît pas. Le même ordre sert la liste mobile et le carrousel de bureau.
 */
final class PlanningDays
{
    /**
     * @param list<RequestableSlot> $slots
     *
     * @return list<PlanningDay>
     */
    public function group(array $slots, \DateTimeImmutable $today): array
    {
        $byWeekday = [];
        foreach ($slots as $slot) {
            $byWeekday[$slot->slot()->weekday()->value][] = $slot;
        }

        $todayIndex = (int) $today->format('N') - 1;
        $days = [];
        for ($offset = 0; $offset < 7; ++$offset) {
            $index = ($todayIndex + $offset) % 7;
            if (!isset($byWeekday[$index])) {
                continue;
            }
            $daySlots = $byWeekday[$index];
            usort($daySlots, static fn (RequestableSlot $a, RequestableSlot $b): int => $a->slot()->startTime() <=> $b->slot()->startTime());
            $days[] = new PlanningDay(Weekday::from($index), $offset === 0, $daySlots);
        }

        return $days;
    }
}
