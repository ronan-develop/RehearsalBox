<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\FreeSlotBookingStatus;
use App\Entity\FreeSlotBooking;
use App\Entity\Requester;
use App\Entity\TimeRange;
use App\Repository\Contract\FreeSlotBookingRepositoryInterface;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Repository\Contract\RecurringSlotRepositoryInterface;
use App\Security\Exception\AccessDeniedException;
use App\Service\Exception\AvailabilityValidationException;
use App\Service\Exception\FreeSlotBookingConflictException;
use App\Service\Exception\RequestAlreadyRespondedException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Réservations libres du local (#263, partie 2) : un membre réserve une plage pour son groupe, un administrateur valide. Le local est
 * exclusif : jamais de chevauchement avec un créneau fixe (tous groupes) ni avec une autre réservation en attente ou validée. Le rôle
 * d'administrateur est vérifié par le contrôleur (AuthGuard), comme pour les créneaux fixes ; ici, seules les règles métier.
 */
final class FreeSlotBookingService
{
    public function __construct(
        private readonly FreeSlotBookingRepositoryInterface $bookings,
        private readonly RecurringSlotRepositoryInterface $slots,
        private readonly GroupRepositoryInterface $groups,
        private readonly FreeSlotBookingPolicy $policy,
        private readonly ClockInterface $clock,
        private readonly int $lockWaitSeconds = 5,
    ) {
    }

    /**
     * @throws AccessDeniedException                 pas membre du groupe
     * @throws AvailabilityValidationException       règles de la politique (erreurs par champ)
     * @throws FreeSlotBookingConflictException      plage prise, ou verrou du jour occupé
     */
    public function request(int $userId, int $groupId, \DateTimeImmutable $date, ?string $startTime, ?string $endTime, ?string $reason): FreeSlotBooking
    {
        // IDOR : le groupe est vérifié contre l'appartenance de la personne, jamais cru sur parole.
        if (!$this->groups->isMember($groupId, $userId)) {
            throw $this->denied();
        }

        $now = $this->clock->now();
        $range = $this->policy->assertAllowed($date, $startTime, $endTime, $reason, $this->bookings->countUpcomingFor($groupId, $now), $now);

        // Le verrou du jour sérialise « vérifier puis insérer » : deux réservations simultanées, une seule passe (409 pour l'autre).
        if (!$this->bookings->acquireDateLock($date, $this->lockWaitSeconds)) {
            throw new FreeSlotBookingConflictException('Une autre réservation est en cours pour ce jour : réessayez dans un instant.');
        }
        try {
            $this->assertFree($date, $range);

            return $this->bookings->create(new Requester($groupId, $userId), $date, $range, $reason);
        } finally {
            $this->bookings->releaseDateLock($date);
        }
    }

    /** @throws AccessDeniedException|RequestAlreadyRespondedException */
    public function cancel(int $userId, int $bookingId): void
    {
        $booking = $this->bookings->findById($bookingId);
        if ($booking === null || !$this->groups->isMember($booking->requester()->groupId(), $userId)) {
            throw $this->denied();
        }

        if (!$this->bookings->cancel($bookingId, $this->clock->now())) {
            throw new RequestAlreadyRespondedException('Cette réservation ne peut plus être annulée.');
        }
    }

    /**
     * @throws AccessDeniedException                 réservation inconnue
     * @throws RequestAlreadyRespondedException      déjà traitée (le premier administrateur gagne)
     * @throws FreeSlotBookingConflictException      un créneau fixe a été ajouté depuis la demande
     */
    public function approve(int $adminId, int $bookingId): FreeSlotBooking
    {
        $booking = $this->existingOrDenied($bookingId);
        // Un créneau fixe a pu être ajouté depuis la demande : les créneaux fixes sont prioritaires.
        $this->assertNoFixedSlot($booking->date(), $booking->range());

        return $this->decide($adminId, $booking, FreeSlotBookingStatus::Validee, null);
    }

    /**
     * @throws AccessDeniedException|RequestAlreadyRespondedException|AvailabilityValidationException motif trop long
     */
    public function refuse(int $adminId, int $bookingId, ?string $note): FreeSlotBooking
    {
        $booking = $this->existingOrDenied($bookingId);
        if ($note !== null && mb_strlen($note) > FreeSlotBookingPolicy::MAX_REASON_LENGTH) {
            throw new AvailabilityValidationException(['decisionNote' => 'Le motif ne peut pas dépasser ' . FreeSlotBookingPolicy::MAX_REASON_LENGTH . ' caractères.']);
        }

        return $this->decide($adminId, $booking, FreeSlotBookingStatus::Refusee, $note === '' ? null : $note);
    }

    /** @return list<FreeSlotBooking> à valider, par date puis heure (écran de l'administrateur) */
    public function pending(): array
    {
        return $this->bookings->findPending();
    }

    /**
     * @return list<FreeSlotBooking>
     *
     * @throws AccessDeniedException pas membre du groupe
     */
    public function forGroup(int $userId, int $groupId): array
    {
        if (!$this->groups->isMember($groupId, $userId)) {
            throw $this->denied();
        }

        return $this->bookings->findForGroup($groupId, $this->clock->now());
    }

    private function assertFree(\DateTimeImmutable $date, TimeRange $range): void
    {
        $this->assertNoFixedSlot($date, $range);
        if ($this->bookings->findOverlapping($date, $range) !== []) {
            throw new FreeSlotBookingConflictException('Cette plage est déjà réservée.');
        }
    }

    /** Un créneau fixe actif (tous groupes) le même jour de semaine : le message ne nomme jamais le groupe titulaire. */
    private function assertNoFixedSlot(\DateTimeImmutable $date, TimeRange $range): void
    {
        $weekday = (int) $date->format('N') - 1;
        foreach ($this->slots->findAllActive() as $slot) {
            if ($slot->weekday()->value === $weekday && $slot->overlaps($range->start(), $range->end())) {
                throw new FreeSlotBookingConflictException('Cette plage chevauche un créneau fixe : faites une demande au groupe concerné.');
            }
        }
    }

    private function existingOrDenied(int $bookingId): FreeSlotBooking
    {
        $booking = $this->bookings->findById($bookingId);
        if ($booking === null) {
            throw $this->denied();
        }

        return $booking;
    }

    private function decide(int $adminId, FreeSlotBooking $booking, FreeSlotBookingStatus $decision, ?string $note): FreeSlotBooking
    {
        if (!$this->bookings->decide($booking->id(), $decision, $adminId, $note, $this->clock->now())) {
            throw new RequestAlreadyRespondedException('Cette réservation a déjà été traitée.');
        }
        $decided = $this->bookings->findById($booking->id());
        \assert($decided !== null);

        return $decided;
    }

    /** Une réservation interdite et une inexistante produisent le même refus (pas d'énumération d'identifiants). */
    private function denied(): AccessDeniedException
    {
        return new AccessDeniedException('Accès refusé.');
    }
}
