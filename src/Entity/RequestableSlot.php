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
    ) {
    }

    /**
     * Carte "occasionnelle" (#34) : le groupe demandeur d'une exception
     * acceptée occupe ponctuellement le créneau du titulaire ce jour-là.
     * $slot reste celui du titulaire (même horaire/jour), seuls le nom et
     * l'id du groupe affiché changent — d'où group=demandeur, slot=titulaire.
     */
    public static function occasional(RecurringSlot $slot, string $requestingGroupName, int $requestingGroupId): self
    {
        return new self($slot, $requestingGroupName, $requestingGroupId, false);
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
}
