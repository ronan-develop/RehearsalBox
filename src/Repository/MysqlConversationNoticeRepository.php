<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Contract\ConversationNoticeRepositoryInterface;

final class MysqlConversationNoticeRepository implements ConversationNoticeRepositoryInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function claimInitial(int $conversationId, int $groupId, \DateTimeImmutable $now): bool
    {
        // INSERT IGNORE sur la clé (conversation, groupe) : atomique, sans lecture préalable.
        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO conversation_group_notices (conversation_id, group_id, notified_at)
             VALUES (:conversation_id, :group_id, :now)'
        );
        $statement->execute(['conversation_id' => $conversationId, 'group_id' => $groupId, 'now' => $now->format(self::DATE_FORMAT)]);

        return $statement->rowCount() === 1;
    }

    public function releaseInitial(int $conversationId, int $groupId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM conversation_group_notices WHERE conversation_id = :conversation_id AND group_id = :group_id');
        $statement->execute(['conversation_id' => $conversationId, 'group_id' => $groupId]);
    }

    public function initialNotifiedAt(int $conversationId, int $groupId): ?\DateTimeImmutable
    {
        $statement = $this->pdo->prepare('SELECT notified_at FROM conversation_group_notices WHERE conversation_id = :conversation_id AND group_id = :group_id');
        $statement->execute(['conversation_id' => $conversationId, 'group_id' => $groupId]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : new \DateTimeImmutable((string) $value);
    }
}
