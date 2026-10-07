<?php

declare(strict_types=1);

namespace App\Planning\Service;

use App\Planning\Entity\Weekday;
use App\Planning\Entity\RecurringSlot;
use App\Planning\Entity\RequestableSlot;

interface SlotServiceInterface
{
    /**
     * @throws \InvalidArgumentException si $endTime <= $startTime
     * @throws \App\Planning\Exception\OverlappingSlotException si le créneau chevauche un créneau fixe actif existant, de n'importe quel groupe (le local est exclusif, #263)
     */
    public function create(int $groupId, Weekday $weekday, string $startTime, string $endTime): RecurringSlot;

    /**
     * @throws \InvalidArgumentException si le créneau n'existe pas, ou si l'heure de fin n'est pas après le début
     * @throws \App\Planning\Exception\OverlappingSlotException si les nouvelles heures chevauchent le créneau fixe d'un autre groupe
     */
    public function update(int $slotId, string $startTime, string $endTime): RecurringSlot;

    public function delete(int $slotId): void;

    /** @return list<RecurringSlot> */
    public function findByGroup(int $groupId): array;

    /** @return list<RecurringSlot> */
    public function findAllActive(): array;

    /** @return list<RequestableSlot> */
    public function findFixedPlanningSlots(): array;

    /** @return list<RequestableSlot> */
    public function findOccasionalPlanningSlots(): array;
}
