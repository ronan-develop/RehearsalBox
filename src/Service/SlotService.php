<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\Weekday;
use App\Entity\RecurringSlot;
use App\Entity\RequestableSlot;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Repository\Contract\RecurringSlotRepositoryInterface;
use App\Service\Contract\SlotServiceInterface;
use App\Service\Exception\OverlappingSlotException;

final class SlotService implements SlotServiceInterface
{
    private const MAX_END_TIME = '23:30:00';

    public function __construct(
        private readonly RecurringSlotRepositoryInterface $slotRepository,
        private readonly GroupRepositoryInterface $groupRepository,
    ) {
    }

    private function assertValidTimes(string $startTime, string $endTime): void
    {
        if ($endTime <= $startTime) {
            throw new \InvalidArgumentException('L’heure de fin doit être après l’heure de début.');
        }

        if ($endTime > self::MAX_END_TIME) {
            throw new \InvalidArgumentException('L’heure de fin ne peut pas dépasser 23h30.');
        }
    }

    public function create(int $groupId, Weekday $weekday, string $startTime, string $endTime): RecurringSlot
    {
        $this->assertValidTimes($startTime, $endTime);

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

        $this->assertValidTimes($startTime, $endTime);

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

    public function findPlanningSlots(): array
    {
        return array_map(
            function (RecurringSlot $slot): RequestableSlot {
                $group = $this->groupRepository->findById($slot->groupId());
                \assert($group !== null);

                return new RequestableSlot($slot, $group->name());
            },
            $this->slotRepository->findAllActive(),
        );
    }
}
