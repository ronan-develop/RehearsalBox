<?php

declare(strict_types=1);

namespace App\Planning\Repository;

/**
 * Verrous applicatifs des réservations. Par DATE (#263) : sérialise les réservations d'un même jour, sans jamais gêner un autre
 * jour (un verrou de lignes sur une date vide ne conviendrait pas : deux verrous de vide se croisent et finissent en blocage
 * mortel). Par GROUPE (#443) : sérialise les demandes d'un même groupe, quelle que soit leur date, pour que la limite de
 * réservations à venir soit relue une fois le verrou tenu. Ordre d'acquisition : le groupe d'abord, puis la date (pas d'impasse).
 */
interface BookingDateLockInterface
{
    /** @return bool faux si le verrou n'a pas été obtenu dans le délai ($waitSeconds) */
    public function acquire(\DateTimeImmutable $date, int $waitSeconds = 5): bool;

    public function release(\DateTimeImmutable $date): void;

    /** @return bool faux si le verrou n'a pas été obtenu dans le délai ($waitSeconds) */
    public function acquireGroup(int $groupId, int $waitSeconds = 5): bool;

    public function releaseGroup(int $groupId): void;
}
