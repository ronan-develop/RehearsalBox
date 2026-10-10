<?php

declare(strict_types=1);

namespace App\Messaging\Repository;

use App\Messaging\Entity\Conversation;
use App\Messaging\Entity\ConversationMessage;
use App\Messaging\Repository\ConversationRepositoryInterface;

final class MysqlConversationRepository implements ConversationRepositoryInterface
{
    private const DATE_FORMAT = ConversationSql::DATE_FORMAT;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function create(int $initiatorGroupId, int $targetGroupId, ?string $title, \DateTimeImmutable $now, ?int $createdBy = null): Conversation
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO conversations (initiator_group_id, target_group_id, created_by, title, created_at)
             VALUES (:initiator, :target, :created_by, :title, :created_at)'
        );
        $statement->execute([
            'initiator' => $initiatorGroupId,
            'target' => $targetGroupId,
            'created_by' => $createdBy,
            'title' => $title,
            'created_at' => $now->format(self::DATE_FORMAT),
        ]);

        return new Conversation((int) $this->pdo->lastInsertId(), $initiatorGroupId, $targetGroupId, $title, $now, $createdBy);
    }

    public function openDirect(int $userId, int $otherUserId, \DateTimeImmutable $now): Conversation
    {
        // Clé unique sur la paire ordonnée : une seule conversation par paire, même si deux créations arrivent ensemble.
        // ON DUPLICATE KEY ne modifie rien d'autre que de rendre l'identifiant de la ligne existante lisible.
        $statement = $this->pdo->prepare(
            'INSERT INTO conversations (direct_low_user_id, direct_high_user_id, created_by, created_at)
             VALUES (:low, :high, :created_by, :created_at)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $statement->execute([
            'low' => min($userId, $otherUserId),
            'high' => max($userId, $otherUserId),
            'created_by' => $userId,
            'created_at' => $now->format(self::DATE_FORMAT),
        ]);

        return $this->findById((int) $this->pdo->lastInsertId())
            ?? throw new \LogicException('Conversation directe introuvable après ouverture.');
    }

    public function rename(int $conversationId, ?string $title): void
    {
        $statement = $this->pdo->prepare('UPDATE conversations SET title = :title WHERE id = :id');
        $statement->execute(['title' => $title, 'id' => $conversationId]);
    }

    public function findById(int $id): ?Conversation
    {
        $statement = $this->pdo->prepare(
            'SELECT id, initiator_group_id, target_group_id, direct_low_user_id, direct_high_user_id, created_by, title, created_at, deleted_at FROM conversations WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : ConversationRows::conversation($row);
    }

    public function listFor(int $userId, string $box, \DateTimeImmutable $inactiveBefore): array
    {
        $sql = 'SELECT c.id, c.initiator_group_id, c.target_group_id, c.direct_low_user_id, c.direct_high_user_id, c.created_by, c.title, c.created_at, c.deleted_at,
                       ' . ConversationSql::LABEL_COLUMNS . ',
                       lm.id AS last_id, lm.author_id AS last_author_id, lu.display_name AS last_author_name,
                       lm.body AS last_body, lm.is_system AS last_is_system, lm.created_at AS last_created_at,
                       ' . ConversationSql::UNREAD_FOR_USER . ' AS unread,
                       ' . ConversationSql::MENTIONED_USER . ' AS mentioned,
                       COALESCE(s.muted, 0) AS muted
                FROM conversations c
                ' . ConversationSql::LABEL_JOINS . '
                ' . ConversationSql::LAST_MESSAGE_JOIN . '
                JOIN users lu ON lu.id = lm.author_id
                LEFT JOIN conversation_states s ON s.conversation_id = c.id AND s.user_id = :state_user
                WHERE c.deleted_at IS NULL AND ' . ConversationSql::visibleTo(':visible_user') . ' AND ' . ConversationSql::boxCondition($box) . '
                ORDER BY lm.created_at DESC, lm.id DESC';

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'unread_user' => $userId,
            'mention_user' => $userId,
            'state_user' => $userId,
            'visible_user' => $userId,
            'label_user' => $userId,
            'cutoff' => $inactiveBefore->format(self::DATE_FORMAT),
        ]);

        return array_map(ConversationRows::summary(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function countUnreadFor(int $userId, \DateTimeImmutable $inactiveBefore, ?string $box = null): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM conversations c
             ' . ConversationSql::LAST_MESSAGE_JOIN . '
             LEFT JOIN conversation_states s ON s.conversation_id = c.id AND s.user_id = :state_user
             WHERE c.deleted_at IS NULL AND ' . ConversationSql::visibleTo(':visible_user') . ' AND ' . ($box === null ? '1 = 1' : ConversationSql::boxCondition($box)) . ' AND ' . ConversationSql::UNREAD_FOR_USER
        );
        $parameters = ['state_user' => $userId, 'visible_user' => $userId, 'unread_user' => $userId];
        if ($box !== null) {
            $parameters['cutoff'] = $inactiveBefore->format(self::DATE_FORMAT);
        }
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    public function participantCount(int $conversationId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM (
                 SELECT gu.user_id FROM conversations c
                 JOIN group_user gu ON gu.group_id IN (c.initiator_group_id, c.target_group_id)
                 WHERE c.id = :conversation_id
                 UNION
                 SELECT cg.user_id FROM conversation_guests cg WHERE cg.conversation_id = :guest_conversation_id
                 UNION
                 SELECT c.direct_low_user_id FROM conversations c WHERE c.id = :low_conversation_id AND c.direct_low_user_id IS NOT NULL
                 UNION
                 SELECT c.direct_high_user_id FROM conversations c WHERE c.id = :high_conversation_id AND c.direct_high_user_id IS NOT NULL
             ) participants'
        );
        $statement->execute([
            'conversation_id' => $conversationId,
            'guest_conversation_id' => $conversationId,
            'low_conversation_id' => $conversationId,
            'high_conversation_id' => $conversationId,
        ]);

        return (int) $statement->fetchColumn();
    }

}
