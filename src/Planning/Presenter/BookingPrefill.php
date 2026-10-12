<?php

declare(strict_types=1);

namespace App\Planning\Presenter;

use App\Planning\Service\FreeSlotBookingPolicy;
use App\Support\QuarterHour;

/**
 * Remplissage de « Réserver le local » (#457) : un lien depuis le planning peut porter `date`, `start` et `end`. Rien n'est cru sur
 * parole : tout ce qui n'est pas un jour réel de la fenêtre de réservation et une plage au quart d'heure, dans les bornes de la
 * politique, est ignoré (la page s'ouvre alors vide, comme avant). Le serveur revérifie de toute façon à la réservation.
 */
final class BookingPrefill
{
    /**
     * @param array<string, mixed> $query
     *
     * @return array{date: string, start: string, end: string}|null
     */
    public static function from(array $query, \DateTimeImmutable $today): ?array
    {
        $date = $query['date'] ?? null;
        $start = $query['start'] ?? null;
        $end = $query['end'] ?? null;
        if (!is_string($date) || !is_string($start) || !is_string($end)) {
            return null;
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($day === false || $day->format('Y-m-d') !== $date) { // « 2026-02-31 » déborde : refusé
            return null;
        }
        $first = $today->setTime(0, 0);
        if ($day < $first || $day > $first->modify('+' . FreeSlotBookingPolicy::MAX_DAYS_AHEAD . ' days')) {
            return null;
        }

        foreach ([$start, $end] as $time) {
            if (preg_match('/^\d{2}:\d{2}$/', $time) !== 1 || !QuarterHour::isAligned($time)) {
                return null;
            }
        }
        if ($end <= $start || $end . ':00' > FreeSlotBookingPolicy::MAX_END_TIME) {
            return null;
        }

        return ['date' => $date, 'start' => $start, 'end' => $end];
    }
}
