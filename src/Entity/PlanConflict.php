<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Ce qui, dans une plage voulue, est déjà pris (#263) : le créneau fixe d'un groupe (`fixed`, sur lequel une demande d'échange est
 * possible, sauf s'il est le sien) ou une réservation en attente ou validée (`booking`, rien à demander). `overlap` est la partie de la
 * plage voulue qui est prise.
 */
final class PlanConflict
{
    public const FIXED = 'fixed';
    public const BOOKING = 'booking';

    public function __construct(
        private readonly string $kind,
        private readonly ?int $slotId,
        private readonly string $groupName,
        private readonly bool $own,
        private readonly TimeRange $overlap,
    ) {
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /** Identifiant du créneau fixe visé (pour y adresser une demande) ; null pour une réservation. */
    public function slotId(): ?int
    {
        return $this->slotId;
    }

    public function groupName(): string
    {
        return $this->groupName;
    }

    /** Vrai si c'est le groupe qui veut réserver qui détient déjà cette plage : rien à lui demander. */
    public function isOwn(): bool
    {
        return $this->own;
    }

    public function overlap(): TimeRange
    {
        return $this->overlap;
    }
}
