<?php

declare(strict_types=1);

namespace App\Planning\Repository;

use App\Planning\Entity\FreeSlotBookingStatus;
use App\Planning\Entity\FreeSlotBooking;
use App\Planning\Entity\Requester;
use App\Planning\Entity\TimeRange;

/** Réservations libres du local (#263). */
interface FreeSlotBookingRepositoryInterface
{
    public function create(Requester $requester, \DateTimeImmutable $date, TimeRange $range, ?string $reason): FreeSlotBooking;

    public function findById(int $id): ?FreeSlotBooking;

    /**
     * Réservations en attente ou validées du même jour dont la plage chevauche $range (les bornes qui se touchent ne chevauchent pas).
     *
     * @return list<FreeSlotBooking>
     */
    public function findOverlapping(\DateTimeImmutable $date, TimeRange $range): array;

    /** Réservations à venir (en attente ou validées) d'un groupe : celles qui n'ont pas fini. */
    public function countUpcomingFor(int $groupId, \DateTimeImmutable $now): int;

    /**
     * Tranche une réservation EN ATTENTE, de façon atomique : faux si elle l'était déjà (le second administrateur perd).
     *
     * @param FreeSlotBookingStatus::Validee|FreeSlotBookingStatus::Refusee $decision
     */
    public function decide(int $id, FreeSlotBookingStatus $decision, int $adminId, ?string $note, \DateTimeImmutable $now): bool;

    /** Annule une réservation en attente ou validée qui n'a pas commencé ; faux sinon (atomique). */
    public function cancel(int $id, \DateTimeImmutable $now): bool;

    /** @return list<FreeSlotBooking> à valider, par date puis heure de début */
    public function findPending(): array;

    /** @return list<FreeSlotBooking> les réservations du groupe à partir d'aujourd'hui (tous états), par date puis heure */
    public function findForGroup(int $groupId, \DateTimeImmutable $now): array;

    /** @return list<FreeSlotBooking> les réservations validées entre deux dates (comprises), pour l'affichage de la semaine */
    public function findValidatedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
