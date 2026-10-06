<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\RequestKind;

/** Une réservation libre d'un groupe de l'utilisateur, présentée dans le bloc « Demandes de créneau » (#292). */
final class DashboardBookingItem implements DashboardRequestItem
{
    public function __construct(
        private readonly FreeSlotBooking $booking,
        private readonly string $groupName,
        private readonly ?string $groupColorHex = null,
    ) {
    }

    public function booking(): FreeSlotBooking
    {
        return $this->booking;
    }

    public function groupName(): string
    {
        return $this->groupName;
    }

    public function groupColorHex(): ?string
    {
        return $this->groupColorHex;
    }

    public function kind(): RequestKind
    {
        return RequestKind::Reservation;
    }

    public function requestId(): int
    {
        return $this->booking->id();
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->booking->createdAt();
    }
}
