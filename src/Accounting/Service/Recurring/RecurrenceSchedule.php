<?php

declare(strict_types=1);

namespace App\Accounting\Service\Recurring;

use App\Accounting\Entity\RecurringTemplate;

/** Calendrier d'un modèle (pur, sans base) : mois d'échéance tous les 1, 3 ou 12 mois depuis le premier mois, jour d'échéance de 1 à 28. */
final class RecurrenceSchedule
{
    private const SAFETY_LIMIT = 1200;

    /** Le mois `$period` (premier jour) est-il une échéance du modèle ? */
    public function isScheduled(RecurringTemplate $template, \DateTimeImmutable $period): bool
    {
        if ($period->format('d') !== '01' || $period < $template->firstMonth) {
            return false;
        }
        $diff = ((int) $period->format('Y') - (int) $template->firstMonth->format('Y')) * 12 + (int) $period->format('n') - (int) $template->firstMonth->format('n');

        return $diff % $template->periodicity->months() === 0;
    }

    public function dueOn(RecurringTemplate $template, \DateTimeImmutable $period): \DateTimeImmutable
    {
        return $period->setDate((int) $period->format('Y'), (int) $period->format('n'), $template->dueDay);
    }

    /**
     * Échéances dont la date est comprise entre `$from` et `$to` incluses, de la plus ancienne à la plus récente.
     *
     * @return list<\DateTimeImmutable> premier jour du mois de chaque échéance
     */
    public function periodsBetween(RecurringTemplate $template, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $periods = [];
        $month = $template->firstMonth;
        for ($i = 0; $i < self::SAFETY_LIMIT && $month <= $to; ++$i) {
            $due = $this->dueOn($template, $month);
            if ($due >= $from && $due <= $to) {
                $periods[] = $month;
            }
            $month = $month->modify('+' . $template->periodicity->months() . ' months');
        }

        return $periods;
    }
}
