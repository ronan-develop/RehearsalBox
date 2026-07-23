<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\Weekday;
use App\Entity\RecurringSlot;
use App\Repository\Contract\RecurringSlotRepositoryInterface;
use App\Service\Contract\SlotServiceInterface;
use App\Service\Exception\OverlappingSlotException;

final class SlotService implements SlotServiceInterface
{
    public function __construct(private readonly RecurringSlotRepositoryInterface $slotRepository)
    {
    }

    public function create(int $groupId, Weekday $weekday, string $startTime, string $endTime): RecurringSlot
    {
        if ($endTime <= $startTime) {
            throw new \InvalidArgumentException('L’heure de fin doit être après l’heure de début.');
        }

        foreach ($this->slotRepository->findByGroup($groupId) as $existing) {
            if ($existing->isActive() && $existing->weekday() === $weekday && $existing->overlaps($startTime, $endTime)) {
                throw new OverlappingSlotException('Ce créneau chevauche un créneau existant du groupe.');
            }
        }

        return $this->slotRepository->save(new RecurringSlot(0, $groupId, $weekday, $startTime, $endTime, true));
    }

    public function update(int $slotId, string $startTime, string $endTime): RecurringSlot
    {
        $slot = $this->slotRepository->findById($slotId);
        if ($slot === null) {
            throw new \InvalidArgumentException("Créneau {$slotId} introuvable.");
        }

        return $this->slotRepository->save(new RecurringSlot(
            $slot->id(),
            $slot->groupId(),
            $slot->weekday(),
            $startTime,
            $endTime,
            $slot->isActive(),
        ));
    }

    public function delete(int $slotId): void
    {
        $slot = $this->slotRepository->findById($slotId);
        if ($slot === null) {
            return;
        }

        $this->slotRepository->save(new RecurringSlot(
            $slot->id(),
            $slot->groupId(),
            $slot->weekday(),
            $slot->startTime(),
            $slot->endTime(),
            false,
        ));
    }

    public function findByGroup(int $groupId): array
    {
        return $this->slotRepository->findByGroup($groupId);
    }

    public function findAllActive(): array
    {
        return $this->slotRepository->findAllActive();
    }
}
