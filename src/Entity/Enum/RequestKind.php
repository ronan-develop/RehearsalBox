<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/** Nature d'une ligne du bloc « Demandes de créneau » (#292) : on doit les distinguer d'un coup d'œil. */
enum RequestKind: string
{
    case Reservation = 'reservation';
    case Echange = 'echange';

    public function label(): string
    {
        return match ($this) {
            self::Reservation => 'Réservation',
            self::Echange => 'Échange',
        };
    }
}
