<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Contract\ConversationGuestRepositoryInterface;

final class MysqlConversationGuestRepository implements ConversationGuestRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function add(int $conversationId, int $userId, int $addedBy, \DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO conversation_guests (conversation_id, user_id, added_by, created_at)
             VALUES (:conversation_id, :user_id, :added_by, :created_at)'
        );
        $statement->execute([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'added_by' => $addedBy,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);

        return $statement->rowCount() === 1;
    }

    public function remove(int $conversationId, int $userId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM conversation_guests WHERE conversation_id = :conversation_id AND user_id = :user_id');
        $statement->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);
    }

    public function isGuest(int $conversationId, int $userId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM conversation_guests WHERE conversation_id = :conversation_id AND user_id = :user_id');
        $statement->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);

        return $statement->fetchColumn() !== false;
    }

    public function addedBy(int $conversationId, int $userId): ?int
    {
        $statement = $this->pdo->prepare('SELECT added_by FROM conversation_guests WHERE conversation_id = :conversation_id AND user_id = :user_id');
        $statement->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (int) $value;
    }
}
