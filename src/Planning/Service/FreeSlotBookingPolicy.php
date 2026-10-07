<?php

declare(strict_types=1);

namespace App\Planning\Service;

use App\Planning\Entity\TimeRange;
use App\Planning\Exception\AvailabilityValidationException;
use App\Support\QuarterHour;

/**
 * Garde-fous d'une réservation libre du local (#263, partie 2). Toutes les valeurs sont des constantes de cette classe :
 * les ajuster ne touche aucune autre. Fonction pure, sans base : le nombre de réservations à venir du groupe est fourni.
 */
final class FreeSlotBookingPolicy
{
    public const MAX_DURATION_MINUTES = 720; // 12 h
    public const MAX_END_TIME = '23:30:00';
    public const MAX_DAYS_AHEAD = 30;
    public const MAX_UPCOMING_PER_GROUP = 3;
    public const MAX_REASON_LENGTH = 255;

    /**
     * @return TimeRange la plage normalisée (HH:MM:SS)
     *
     * @throws AvailabilityValidationException erreurs par champ : bookingDate, startTime, endTime, reason, group
     */
    public function assertAllowed(\DateTimeImmutable $date, ?string $startTime, ?string $endTime, ?string $reason, int $upcomingCount, \DateTimeImmutable $now): TimeRange
    {
        $errors = $this->dateErrors($date, $now) + $this->timeErrors($startTime, $endTime);
        $range = $errors === [] ? $this->range($startTime, $endTime) : null;

        if ($range !== null && $date->format('Y-m-d') === $now->format('Y-m-d') && $range->start() <= $now->format('H:i:s')) {
            $errors['startTime'] = 'Le début doit être dans le futur.';
        }
        if ($reason !== null && mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            $errors['reason'] = 'Le motif ne peut pas dépasser ' . self::MAX_REASON_LENGTH . ' caractères.';
        }
        if ($upcomingCount >= self::MAX_UPCOMING_PER_GROUP) {
            $errors['group'] = 'Votre groupe a déjà ' . self::MAX_UPCOMING_PER_GROUP . ' réservations à venir.';
        }

        if ($errors !== [] || $range === null) {
            throw new AvailabilityValidationException($errors);
        }

        return $range;
    }

    /** @return array<string, string> */
    private function dateErrors(\DateTimeImmutable $date, \DateTimeImmutable $now): array
    {
        $today = $now->setTime(0, 0);
        if ($date < $today) {
            return ['bookingDate' => 'La date ne peut pas être dans le passé.'];
        }
        if ($date > $today->modify('+' . self::MAX_DAYS_AHEAD . ' days')) {
            return ['bookingDate' => 'La réservation est possible jusqu’à ' . self::MAX_DAYS_AHEAD . ' jours à l’avance.'];
        }

        return [];
    }

    /** @return array<string, string> */
    private function timeErrors(?string $startTime, ?string $endTime): array
    {
        if ($startTime === null || $endTime === null || $startTime === '' || $endTime === '') {
            return ['startTime' => 'Indiquez l’heure de début et l’heure de fin.'];
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
        if ($end > self::MAX_END_TIME) {
            return ['endTime' => 'La réservation ne peut pas se terminer après 23h30.'];
        }
        if ($this->minutes($end) - $this->minutes($start) > self::MAX_DURATION_MINUTES) {
            return ['endTime' => 'Une réservation dure au plus ' . intdiv(self::MAX_DURATION_MINUTES, 60) . ' heures.'];
        }

        return [];
    }

    private function range(?string $startTime, ?string $endTime): ?TimeRange
    {
        return $startTime === null || $endTime === null ? null : new TimeRange(QuarterHour::normalise($startTime), QuarterHour::normalise($endTime));
    }

    private function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
