<?php

declare(strict_types=1);

namespace App\Planning\Entity;

use App\Planning\Entity\FreeSlotBookingStatus;

/** Réservation d'un créneau libre du local par un groupe (#263) : une date, une plage, validée par un administrateur. */
final class FreeSlotBooking
{
    public function __construct(
        private readonly int $id,
        private readonly Requester $requester,
        private readonly \DateTimeImmutable $date,
        private readonly TimeRange $range,
        private readonly FreeSlotBookingStatus $status,
        private readonly ?string $reason,
        private readonly ?string $decisionNote,
        private readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function requester(): Requester
    {
        return $this->requester;
    }

    public function date(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function range(): TimeRange
    {
        return $this->range;
    }

    public function status(): FreeSlotBookingStatus
    {
        return $this->status;
    }

    /** Motif donné par le demandeur. */
    public function reason(): ?string
    {
        return $this->reason;
    }

    /** Motif facultatif donné par l'administrateur qui a refusé. */
    public function decisionNote(): ?string
    {
        return $this->decisionNote;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Vrai tant que la réservation bloque sa plage : en attente ou validée (un refus ou une annulation la libère). */
    public function occupies(): bool
    {
        return $this->status === FreeSlotBookingStatus::EnAttente || $this->status === FreeSlotBookingStatus::Validee;
    }
}
