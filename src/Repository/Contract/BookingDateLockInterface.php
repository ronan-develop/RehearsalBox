<?php

declare(strict_types=1);

namespace App\Repository\Contract;

/**
 * Verrou applicatif par DATE (#263) : sérialise les réservations d'un même jour, sans jamais gêner un autre jour. Un verrou de
 * lignes sur une date vide ne conviendrait pas : deux verrous de vide se croisent et finissent en blocage mortel.
 */
interface BookingDateLockInterface
{
    /** @return bool faux si le verrou n'a pas été obtenu dans le délai ($waitSeconds) */
    public function acquire(\DateTimeImmutable $date, int $waitSeconds = 5): bool;

    public function release(\DateTimeImmutable $date): void;
}
