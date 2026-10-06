<?php

declare(strict_types=1);

namespace App\Service\Exception;

/** Une réservation libre qui ne peut pas avoir lieu : plage prise par un créneau fixe ou une autre réservation, ou verrou occupé (409). */
final class FreeSlotBookingConflictException extends \RuntimeException
{
}
