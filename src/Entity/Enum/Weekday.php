<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/** 0=lundi .. 6=dimanche — convention alignée sur recurring_slots.weekday. */
enum Weekday: int
{
    case Monday = 0;
    case Tuesday = 1;
    case Wednesday = 2;
    case Thursday = 3;
    case Friday = 4;
    case Saturday = 5;
    case Sunday = 6;
}
