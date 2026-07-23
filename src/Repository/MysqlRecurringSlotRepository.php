<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\Weekday;
use App\Entity\RecurringSlot;
use App\Repository\Contract\RecurringSlotRepositoryInterface;

final class MysqlRecurringSlotRepository implements RecurringSlotRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function findById(int $id): ?RecurringSlot
    {
        $statement = $this->pdo->prepare('SELECT * FROM recurring_slots WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function findAllActive(): array
    {
        $rows = $this->pdo
            ->query('SELECT * FROM recurring_slots WHERE is_active = 1 ORDER BY weekday, start_time')
            ->fetchAll(\PDO::FETCH_ASSOC);

        return array_map($this->hydrate(...), $rows);
    }

    public function findByGroup(int $groupId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM recurring_slots WHERE group_id = :group_id ORDER BY weekday, start_time'
        );
        $statement->execute(['group_id' => $groupId]);

        return array_map($this->hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function save(RecurringSlot $slot): RecurringSlot
    {
        if ($slot->id() === 0) {
            $statement = $this->pdo->prepare(
                'INSERT INTO recurring_slots (group_id, weekday, start_time, end_time, is_active)
                 VALUES (:group_id, :weekday, :start_time, :end_time, :is_active)'
            );
            $statement->execute([
                'group_id' => $slot->groupId(),
                'weekday' => $slot->weekday()->value,
                'start_time' => $slot->startTime(),
                'end_time' => $slot->endTime(),
                'is_active' => (int) $slot->isActive(),
            ]);

            return $this->findById((int) $this->pdo->lastInsertId());
        }

        $statement = $this->pdo->prepare(
            'UPDATE recurring_slots SET group_id = :group_id, weekday = :weekday, start_time = :start_time,
             end_time = :end_time, is_active = :is_active WHERE id = :id'
        );
        $statement->execute([
            'id' => $slot->id(),
            'group_id' => $slot->groupId(),
            'weekday' => $slot->weekday()->value,
            'start_time' => $slot->startTime(),
            'end_time' => $slot->endTime(),
            'is_active' => (int) $slot->isActive(),
        ]);

        return $this->findById($slot->id());
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): RecurringSlot
    {
        return new RecurringSlot(
            id: (int) $row['id'],
            groupId: (int) $row['group_id'],
            weekday: Weekday::from((int) $row['weekday']),
            startTime: (string) $row['start_time'],
            endTime: (string) $row['end_time'],
            isActive: (bool) $row['is_active'],
        );
    }
}
