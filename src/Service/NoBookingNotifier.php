<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FreeSlotBooking;
use App\Service\Contract\BookingNotifierInterface;

/** Objet nul : scripts et tests qui n'envoient aucune notification. */
final class NoBookingNotifier implements BookingNotifierInterface
{
    public function bookingRequested(FreeSlotBooking $booking): void
    {
    }

    public function bookingDecided(FreeSlotBooking $booking): void
    {
    }
}
