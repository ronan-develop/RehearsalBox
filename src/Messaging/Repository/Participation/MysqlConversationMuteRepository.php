<?php

declare(strict_types=1);

namespace App\Messaging\Repository\Participation;

use App\Messaging\Repository\Participation\ConversationMuteRepositoryInterface;

final class MysqlConversationMuteRepository implements ConversationMuteRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function isMuted(int $conversationId, int $userId): bool
    {
        $statement = $this->pdo->prepare('SELECT muted FROM conversation_states WHERE conversation_id = :conversation_id AND user_id = :user_id');
        $statement->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function setMuted(int $conversationId, int $userId, bool $muted): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO conversation_states (conversation_id, user_id, muted) VALUES (:conversation_id, :user_id, :muted)
             ON DUPLICATE KEY UPDATE muted = VALUES(muted)'
        );
        $statement->execute(['conversation_id' => $conversationId, 'user_id' => $userId, 'muted' => $muted ? 1 : 0]);
    }
}
