<?php

declare(strict_types=1);

namespace App\Account\Repository;

use App\Messaging\Crypto\MessageCipher;

final class MysqlAccountDataRepository implements AccountDataRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo, private readonly MessageCipher $cipher)
    {
    }

    public function memberships(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT g.id, g.name, gu.role FROM group_user gu JOIN `groups` g ON g.id = gu.group_id WHERE gu.user_id = :user_id ORDER BY g.name, g.id'
        );
        $statement->execute(['user_id' => $userId]);

        return array_map(
            static fn (array $row): array => ['groupId' => (int) $row['id'], 'group' => (string) $row['name'], 'role' => (string) $row['role']],
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    public function messages(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, conversation_id, body, created_at, edited_at FROM conversation_messages WHERE author_id = :user_id AND is_system = 0 ORDER BY id'
        );
        $statement->execute(['user_id' => $userId]);

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'conversationId' => (int) $row['conversation_id'],
            'sentAt' => (string) $row['created_at'],
            'editedAt' => $row['edited_at'] === null ? null : (string) $row['edited_at'],
            'text' => $this->cipher->decrypt((string) $row['body']),
        ], $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function bookings(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT g.name, b.booking_date, b.start_time, b.end_time, b.status, b.reason, b.created_at
             FROM free_slot_bookings b JOIN `groups` g ON g.id = b.group_id WHERE b.booked_by_user_id = :user_id ORDER BY b.booking_date, b.start_time, b.id'
        );
        $statement->execute(['user_id' => $userId]);

        return array_map(static fn (array $row): array => [
            'group' => (string) $row['name'],
            'date' => (string) $row['booking_date'],
            'start' => substr((string) $row['start_time'], 0, 5),
            'end' => substr((string) $row['end_time'], 0, 5),
            'status' => (string) $row['status'],
            'reason' => $row['reason'] === null ? null : (string) $row['reason'],
            'createdAt' => (string) $row['created_at'],
        ], $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function slotRequests(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT g.name, e.occurrence_date, e.status, e.request_reason, e.created_at
             FROM slot_exceptions e JOIN `groups` g ON g.id = e.requested_by_group_id WHERE e.requested_by_user_id = :user_id ORDER BY e.occurrence_date, e.id'
        );
        $statement->execute(['user_id' => $userId]);

        return array_map(static fn (array $row): array => [
            'group' => (string) $row['name'],
            'date' => (string) $row['occurrence_date'],
            'status' => (string) $row['status'],
            'reason' => $row['request_reason'] === null ? null : (string) $row['request_reason'],
            'createdAt' => (string) $row['created_at'],
        ], $statement->fetchAll(\PDO::FETCH_ASSOC));
    }
}
