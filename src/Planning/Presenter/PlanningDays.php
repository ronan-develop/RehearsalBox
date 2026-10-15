<?php

declare(strict_types=1);

namespace App\Planning\Presenter;

use App\Planning\Entity\Weekday;
use App\Planning\Entity\RequestableSlot;

/**
 * Regroupe les créneaux par jour, à partir d'AUJOURD'HUI (#201) : la semaine se lit de ce qui arrive en premier. Un jour sans créneau
 * n'apparaît pas. Le même ordre sert la liste mobile et le carrousel de bureau.
 *
 * Les créneaux occasionnels de la semaine (#461 : échanges acceptés, réservations validées) rejoignent leur jour, triés par heure avec
 * les créneaux fixes. Ceux d'avant aujourd'hui sont écartés : classés par jour de semaine, un lundi passé se lirait « lundi prochain ».
 * Un échange accepté note aussi, sur le jour, ce que le groupe titulaire a cédé (une réservation libre ne cède rien : id 0).
 */
final class PlanningDays
{
    /**
     * @param list<RequestableSlot> $slots      créneaux fixes
     * @param list<RequestableSlot> $occasional créneaux occasionnels de la semaine
     *
     * @return list<PlanningDay>
     */
    public function group(array $slots, \DateTimeImmutable $today, array $occasional = []): array
    {
        $byWeekday = [];
        $fixedById = [];
        foreach ($slots as $slot) {
            $byWeekday[$slot->slot()->weekday()->value][] = $slot;
            $fixedById[$slot->slot()->id()] = $slot->slot();
        }

        $cessions = [];
        foreach ($occasional as $slot) {
            $date = $slot->occurrenceDate();
            if ($date === null || $date->format('Y-m-d') < $today->format('Y-m-d')) {
                continue;
            }
            $weekday = $slot->slot()->weekday()->value;
            $byWeekday[$weekday][] = $slot;
            $holder = $fixedById[$slot->slot()->id()] ?? null;
            if ($holder !== null) {
                $partial = $slot->slot()->startTime() !== $holder->startTime() || $slot->slot()->endTime() !== $holder->endTime();
                $cessions[$weekday][$holder->id()][] = new SlotCession($slot->groupName(), $date, $partial ? self::range($slot) : null);
            }
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
            $days[] = new PlanningDay(Weekday::from($index), $offset === 0, $daySlots, $cessions[$index] ?? []);
        }

        return $days;
    }

    private static function range(RequestableSlot $slot): string
    {
        return substr($slot->slot()->startTime(), 0, 5) . ' – ' . substr($slot->slot()->endTime(), 0, 5);
    }
}
