<?php

declare(strict_types=1);

namespace App\Planning\Service;

use App\Planning\Entity\RecurringSlot;
use App\Planning\Entity\TimeRange;
use App\Support\QuarterHour;

/** Règles de la plage d'une demande d'échange (#263) : pures, sans état ; AvailabilityService les applique avant d'écrire. */
final class SlotRequestRange
{
    /**
     * Plage partielle (#263) : les deux bornes ensemble, sur le quart d'heure, début avant fin, comprise dans le créneau du titulaire
     * (le reste du créneau reste au titulaire). Sans borne, la demande vise tout le créneau.
     *
     * @return array<string, string> erreurs par champ
     */
    public static function errors(RecurringSlot $slot, ?string $startTime, ?string $endTime): array
    {
        if ($startTime === null && $endTime === null) {
            return [];
        }
        if ($startTime === null || $endTime === null) {
            return ['startTime' => 'Indiquez l’heure de début et l’heure de fin, ou aucune des deux.'];
        }

        $errors = [];
        foreach (['startTime' => $startTime, 'endTime' => $endTime] as $field => $time) {
            if (!QuarterHour::isAligned($time)) {
                $errors[$field] = 'L’heure doit tomber sur un quart d’heure (par exemple 18:30).';
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        [$start, $end] = [QuarterHour::normalise($startTime), QuarterHour::normalise($endTime)];
        if ($end <= $start) {
            return ['endTime' => 'La fin doit être après le début.'];
        }
        if (!(new TimeRange($start, $end))->isWithin(new TimeRange($slot->startTime(), $slot->endTime()))) {
            return ['startTime' => 'La plage doit rester dans le créneau du groupe titulaire.'];
        }

        return [];
    }
}
