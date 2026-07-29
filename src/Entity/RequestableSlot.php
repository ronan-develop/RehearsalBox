<?php

declare(strict_types=1);

namespace App\Entity;

final class RequestableSlot
{
    public function __construct(
        private readonly RecurringSlot $slot,
        private readonly string $groupName,
        private readonly int $groupId,
        private readonly bool $isRecurring = true,
        private readonly ?\DateTimeImmutable $occurrenceDate = null,
    ) {
    }

    /**
     * Carte "occasionnelle" (#34) : le groupe demandeur d'une exception
     * acceptée occupe ponctuellement le créneau du titulaire ce jour-là.
     * $slot reste celui du titulaire (même horaire/jour), seuls le nom et
     * l'id du groupe affiché changent — d'où group=demandeur, slot=titulaire.
     * $occurrenceDate est affichée sur la carte (#81) pour lever toute
     * ambiguïté avec un créneau fixe récurrent.
     */
    public static function occasional(RecurringSlot $slot, string $requestingGroupName, int $requestingGroupId, \DateTimeImmutable $occurrenceDate): self
    {
        return new self($slot, $requestingGroupName, $requestingGroupId, false, $occurrenceDate);
    }

    public function slot(): RecurringSlot
    {
        return $this->slot;
    }

    public function groupName(): string
    {
        return $this->groupName;
    }

    public function groupId(): int
    {
        return $this->groupId;
    }

    public function isRecurring(): bool
    {
        return $this->isRecurring;
    }

    public function occurrenceDate(): ?\DateTimeImmutable
    {
        return $this->occurrenceDate;
    }
}
