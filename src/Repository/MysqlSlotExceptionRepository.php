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

    public function findPendingForHolderGroup(int $groupId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT se.* FROM slot_exceptions se
             INNER JOIN recurring_slots rs ON rs.id = se.recurring_slot_id
             WHERE rs.group_id = :group_id AND se.status = 'en_attente'
             ORDER BY se.occurrence_date"
        );
        $statement->execute(['group_id' => $groupId]);

        return array_map($this->hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function findByRequestingGroup(int $groupId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM slot_exceptions
             WHERE requested_by_group_id = :group_id
             ORDER BY occurrence_date'
        );
        $statement->execute(['group_id' => $groupId]);

        return array_map($this->hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function createRequest(
        int $recurringSlotId,
        \DateTimeImmutable $occurrenceDate,
        int $requestedByGroupId,
        int $requestedByUserId,
        ?string $reason,
    ): SlotException {
        $statement = $this->pdo->prepare(
            "INSERT INTO slot_exceptions (recurring_slot_id, occurrence_date, status, requested_by_group_id, requested_by_user_id, request_reason)
             VALUES (:recurring_slot_id, :occurrence_date, 'en_attente', :requested_by_group_id, :requested_by_user_id, :request_reason)"
        );
        $statement->execute([
            'recurring_slot_id' => $recurringSlotId,
            'occurrence_date' => $occurrenceDate->format('Y-m-d'),
            'requested_by_group_id' => $requestedByGroupId,
            'requested_by_user_id' => $requestedByUserId,
            'request_reason' => $reason,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function respond(int $exceptionId, bool $accepted, int $respondedByUserId): bool
    {
        $newStatus = $accepted ? SlotExceptionStatus::Acceptee : SlotExceptionStatus::Refusee;

        $statement = $this->pdo->prepare(
            "UPDATE slot_exceptions
             SET status = :new_status, responded_by_user_id = :user_id, responded_at = NOW()
             WHERE id = :id AND status = 'en_attente'"
        );
        $statement->execute([
            'new_status' => $newStatus->value,
            'id' => $exceptionId,
            'user_id' => $respondedByUserId,
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
            requestedByGroupId: (int) $row['requested_by_group_id'],
            requestedByUserId: (int) $row['requested_by_user_id'],
            requestReason: $row['request_reason'] !== null ? (string) $row['request_reason'] : null,
            respondedByUserId: $row['responded_by_user_id'] !== null ? (int) $row['responded_by_user_id'] : null,
        );
    }
}
