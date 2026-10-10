<?php

declare(strict_types=1);

namespace App\Messaging\Repository\Participation;

use App\Messaging\Repository\Participation\ConversationTrashRepositoryInterface;
use App\Messaging\Repository\ConversationRows;
use App\Messaging\Repository\ConversationSql;

final class MysqlConversationTrashRepository implements ConversationTrashRepositoryInterface
{
    private const DATE_FORMAT = ConversationSql::DATE_FORMAT;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function moveToTrash(int $conversationId, \DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare('UPDATE conversations SET deleted_at = :now WHERE id = :id');
        $statement->execute(['now' => $now->format(self::DATE_FORMAT), 'id' => $conversationId]);
    }

    public function restore(int $conversationId): void
    {
        $statement = $this->pdo->prepare('UPDATE conversations SET deleted_at = NULL WHERE id = :id');
        $statement->execute(['id' => $conversationId]);
    }

    public function delete(int $conversationId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM conversations WHERE id = :id');
        $statement->execute(['id' => $conversationId]);
    }

    public function listTrashedBy(int $userId, \DateTimeImmutable $trashedSince): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.initiator_group_id, c.target_group_id, c.direct_low_user_id, c.direct_high_user_id, c.created_by, c.title, c.created_at, c.deleted_at,
                    ' . ConversationSql::LABEL_COLUMNS . ',
                    lm.id AS last_id, lm.author_id AS last_author_id, lu.display_name AS last_author_name,
                    lm.body AS last_body, lm.is_system AS last_is_system, lm.created_at AS last_created_at
             FROM conversations c
             ' . ConversationSql::LABEL_JOINS . '
             ' . ConversationSql::LAST_MESSAGE_JOIN . '
             JOIN users lu ON lu.id = lm.author_id
             WHERE c.created_by = :user_id AND c.deleted_at IS NOT NULL AND c.deleted_at >= :since
             ORDER BY c.deleted_at DESC, c.id DESC'
        );
        $statement->execute(['user_id' => $userId, 'label_user' => $userId, 'since' => $trashedSince->format(self::DATE_FORMAT)]);

        return array_map(ConversationRows::summary(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function purgeTrashedBefore(\DateTimeImmutable $cutoff): int
    {
        $statement = $this->pdo->prepare('DELETE FROM conversations WHERE deleted_at IS NOT NULL AND deleted_at < :cutoff');
        $statement->execute(['cutoff' => $cutoff->format(self::DATE_FORMAT)]);

        return $statement->rowCount();
    }
}
