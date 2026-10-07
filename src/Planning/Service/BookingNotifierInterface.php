<?php

declare(strict_types=1);

namespace App\Planning\Service;

use App\Planning\Entity\FreeSlotBooking;

/** Notifications des réservations libres (#263) : jamais bloquantes, jamais de désabonnement général. */
interface BookingNotifierInterface
{
    /** Une réservation attend une décision : tous les administrateurs actifs en sont prévenus. */
    public function bookingRequested(FreeSlotBooking $booking): void;

    /** Une réservation vient d'être validée ou refusée : la personne qui l'a demandée en est prévenue. */
    public function bookingDecided(FreeSlotBooking $booking): void;
}
