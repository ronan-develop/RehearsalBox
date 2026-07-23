<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\SlotExceptionStatus;
use App\Entity\SlotException;
use App\Repository\Contract\SlotExceptionRepositoryInterface;

final class MysqlSlotExceptionRepository implements SlotExceptionRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function findById(int $id): ?SlotException
    {
        $statement = $this->pdo->prepare('SELECT * FROM slot_exceptions WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function findLiberatedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM slot_exceptions
             WHERE status = 'liberee' AND occurrence_date BETWEEN :from AND :to
             ORDER BY occurrence_date"
        );
        $statement->execute([
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]);

        return array_map($this->hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function createLiberation(
        int $recurringSlotId,
        \DateTimeImmutable $occurrenceDate,
        int $releasedByUserId,
        ?string $reason,
    ): SlotException {
        $statement = $this->pdo->prepare(
            "INSERT INTO slot_exceptions (recurring_slot_id, occurrence_date, status, released_by_user_id, released_reason)
             VALUES (:recurring_slot_id, :occurrence_date, 'liberee', :released_by_user_id, :released_reason)"
        );
        $statement->execute([
            'recurring_slot_id' => $recurringSlotId,
            'occurrence_date' => $occurrenceDate->format('Y-m-d'),
            'released_by_user_id' => $releasedByUserId,
            'released_reason' => $reason,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function claim(int $exceptionId, int $groupId, int $userId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE slot_exceptions
             SET status = 'revendiquee', claimed_by_group_id = :group_id, claimed_by_user_id = :user_id, claimed_at = NOW()
             WHERE id = :id AND status = 'liberee'"
        );
        $statement->execute([
            'id' => $exceptionId,
            'group_id' => $groupId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): SlotException
    {
        return new SlotException(
            id: (int) $row['id'],
            recurringSlotId: (int) $row['recurring_slot_id'],
            occurrenceDate: new \DateTimeImmutable((string) $row['occurrence_date']),
            status: SlotExceptionStatus::from((string) $row['status']),
            releasedByUserId: (int) $row['released_by_user_id'],
            releasedReason: $row['released_reason'] !== null ? (string) $row['released_reason'] : null,
            claimedByGroupId: $row['claimed_by_group_id'] !== null ? (int) $row['claimed_by_group_id'] : null,
            claimedByUserId: $row['claimed_by_user_id'] !== null ? (int) $row['claimed_by_user_id'] : null,
        );
    }
}
