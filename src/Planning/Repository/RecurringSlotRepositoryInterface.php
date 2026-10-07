<?php

declare(strict_types=1);

namespace App\Planning\Repository;

use App\Planning\Entity\RecurringSlot;

interface RecurringSlotRepositoryInterface
{
    public function findById(int $id): ?RecurringSlot;

    /** @return list<RecurringSlot> */
    public function findAllActive(): array;

    /** @return list<RecurringSlot> */
    public function findByGroup(int $groupId): array;

    public function save(RecurringSlot $slot): RecurringSlot;
}
