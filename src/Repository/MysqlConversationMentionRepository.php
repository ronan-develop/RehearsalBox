<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Contract\ConversationMentionRepositoryInterface;

final class MysqlConversationMentionRepository implements ConversationMentionRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function record(int $messageId, array $labelsByUserId): void
    {
        $delete = $this->pdo->prepare('DELETE FROM message_mentions WHERE message_id = :message_id');
        $delete->execute(['message_id' => $messageId]);

        $insert = $this->pdo->prepare('INSERT INTO message_mentions (message_id, user_id, label) VALUES (:message_id, :user_id, :label)');
        foreach ($labelsByUserId as $userId => $label) {
            $insert->execute(['message_id' => $messageId, 'user_id' => $userId, 'label' => $label]);
        }
    }

    public function forMessages(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($messageIds), '?'));
        $statement = $this->pdo->prepare("SELECT message_id, user_id, label FROM message_mentions WHERE message_id IN ({$placeholders}) ORDER BY message_id, user_id");
        $statement->execute(array_values($messageIds));

        $result = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['message_id']][(int) $row['user_id']] = (string) $row['label'];
        }

        return $result;
    }
}
