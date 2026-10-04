<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Entity\ConversationSummary;
use App\Repository\Contract\ConversationRepositoryInterface;

final class MysqlConversationRepository implements ConversationRepositoryInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    // Visible = la personne est membre de l'un des deux groupes (les deux comptent une seule fois : EXISTS).
    private const VISIBLE_TO_USER = 'EXISTS (
        SELECT 1 FROM group_user gu
        WHERE gu.user_id = :visible_user AND gu.group_id IN (c.initiator_group_id, c.target_group_id)
    )';

    private const UNREAD_FOR_USER = 'EXISTS (
        SELECT 1 FROM conversation_messages um
        WHERE um.conversation_id = c.id AND um.author_id <> :unread_user
          AND (s.last_read_at IS NULL OR um.created_at > s.last_read_at)
    )';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function create(int $initiatorGroupId, int $targetGroupId, string $subject, \DateTimeImmutable $now): Conversation
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO conversations (initiator_group_id, target_group_id, subject, created_at)
             VALUES (:initiator, :target, :subject, :created_at)'
        );
        $statement->execute([
            'initiator' => $initiatorGroupId,
            'target' => $targetGroupId,
            'subject' => $subject,
            'created_at' => $now->format(self::DATE_FORMAT),
        ]);

        return new Conversation((int) $this->pdo->lastInsertId(), $initiatorGroupId, $targetGroupId, $subject, $now);
    }

    public function addMessage(int $conversationId, int $authorId, string $body, \DateTimeImmutable $now): ConversationMessage
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO conversation_messages (conversation_id, author_id, body, created_at)
             VALUES (:conversation_id, :author_id, :body, :created_at)'
        );
        $statement->execute([
            'conversation_id' => $conversationId,
            'author_id' => $authorId,
            'body' => $body,
            'created_at' => $now->format(self::DATE_FORMAT),
        ]);

        $name = $this->pdo->prepare('SELECT display_name FROM users WHERE id = :id');
        $name->execute(['id' => $authorId]);

        return new ConversationMessage((int) $this->pdo->lastInsertId(), $conversationId, $authorId, (string) $name->fetchColumn(), $body, $now);
    }

    public function findById(int $id): ?Conversation
    {
        $statement = $this->pdo->prepare(
            'SELECT id, initiator_group_id, target_group_id, subject, created_at FROM conversations WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrateConversation($row);
    }

    public function messagesOf(int $conversationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT m.id, m.conversation_id, m.author_id, u.display_name AS author_name, m.body, m.created_at
             FROM conversation_messages m JOIN users u ON u.id = m.author_id
             WHERE m.conversation_id = :conversation_id
             ORDER BY m.created_at ASC, m.id ASC'
        );
        $statement->execute(['conversation_id' => $conversationId]);

        return array_map(fn (array $row): ConversationMessage => $this->hydrateMessage($row), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function listFor(int $userId, string $box): array
    {
        $archivedFilter = $box === self::BOX_ARCHIVED ? 'COALESCE(s.archived, 0) = 1' : 'COALESCE(s.archived, 0) = 0';
        $sentFilter = $box === self::BOX_SENT
            ? 'AND EXISTS (SELECT 1 FROM conversation_messages sm WHERE sm.conversation_id = c.id AND sm.author_id = :sent_user)'
            : '';

        $sql = 'SELECT c.id, c.initiator_group_id, c.target_group_id, c.subject, c.created_at,
                       gi.name AS initiator_name, gt.name AS target_name,
                       lm.id AS last_id, lm.author_id AS last_author_id, lu.display_name AS last_author_name,
                       lm.body AS last_body, lm.created_at AS last_created_at,
                       mm.id AS mine_id, mm.author_id AS mine_author_id, mu.display_name AS mine_author_name,
                       mm.body AS mine_body, mm.created_at AS mine_created_at,
                       ' . self::UNREAD_FOR_USER . ' AS unread
                FROM conversations c
                JOIN `groups` gi ON gi.id = c.initiator_group_id
                JOIN `groups` gt ON gt.id = c.target_group_id
                JOIN conversation_messages lm ON lm.id = (SELECT MAX(x.id) FROM conversation_messages x WHERE x.conversation_id = c.id)
                JOIN users lu ON lu.id = lm.author_id
                LEFT JOIN conversation_messages mm ON mm.id = (
                    SELECT MAX(y.id) FROM conversation_messages y WHERE y.conversation_id = c.id AND y.author_id = :mine_user
                )
                LEFT JOIN users mu ON mu.id = mm.author_id
                LEFT JOIN conversation_states s ON s.conversation_id = c.id AND s.user_id = :state_user
                WHERE ' . self::VISIBLE_TO_USER . ' AND ' . $archivedFilter . ' ' . $sentFilter . '
                ORDER BY lm.created_at DESC, lm.id DESC';

        $parameters = [
            'unread_user' => $userId,
            'mine_user' => $userId,
            'state_user' => $userId,
            'visible_user' => $userId,
        ];
        if ($box === self::BOX_SENT) {
            $parameters['sent_user'] = $userId;
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return array_map(
            fn (array $row): ConversationSummary => new ConversationSummary(
                $this->hydrateConversation($row),
                (string) $row['initiator_name'],
                (string) $row['target_name'],
                new ConversationMessage((int) $row['last_id'], (int) $row['id'], (int) $row['last_author_id'], (string) $row['last_author_name'], (string) $row['last_body'], new \DateTimeImmutable($row['last_created_at'])),
                $row['mine_id'] === null ? null : new ConversationMessage((int) $row['mine_id'], (int) $row['id'], (int) $row['mine_author_id'], (string) $row['mine_author_name'], (string) $row['mine_body'], new \DateTimeImmutable($row['mine_created_at'])),
                (bool) $row['unread'],
            ),
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    public function markRead(int $conversationId, int $userId, \DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO conversation_states (conversation_id, user_id, last_read_at) VALUES (:conversation_id, :user_id, :now)
             ON DUPLICATE KEY UPDATE last_read_at = VALUES(last_read_at)'
        );
        $statement->execute(['conversation_id' => $conversationId, 'user_id' => $userId, 'now' => $now->format(self::DATE_FORMAT)]);
    }

    public function setArchived(int $conversationId, int $userId, bool $archived): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO conversation_states (conversation_id, user_id, archived) VALUES (:conversation_id, :user_id, :archived)
             ON DUPLICATE KEY UPDATE archived = VALUES(archived)'
        );
        $statement->execute(['conversation_id' => $conversationId, 'user_id' => $userId, 'archived' => (int) $archived]);
    }

    public function countMessagesBySince(int $authorId, \DateTimeImmutable $since): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM conversation_messages WHERE author_id = :author_id AND created_at >= :since');
        $statement->execute(['author_id' => $authorId, 'since' => $since->format(self::DATE_FORMAT)]);

        return (int) $statement->fetchColumn();
    }

    public function countUnreadFor(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM conversations c
             LEFT JOIN conversation_states s ON s.conversation_id = c.id AND s.user_id = :state_user
             WHERE ' . self::VISIBLE_TO_USER . ' AND COALESCE(s.archived, 0) = 0 AND ' . self::UNREAD_FOR_USER
        );
        $statement->execute(['state_user' => $userId, 'visible_user' => $userId, 'unread_user' => $userId]);

        return (int) $statement->fetchColumn();
    }

    /** @param array<string, mixed> $row */
    private function hydrateConversation(array $row): Conversation
    {
        return new Conversation(
            (int) $row['id'],
            (int) $row['initiator_group_id'],
            (int) $row['target_group_id'],
            (string) $row['subject'],
            new \DateTimeImmutable($row['created_at']),
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateMessage(array $row): ConversationMessage
    {
        return new ConversationMessage(
            (int) $row['id'],
            (int) $row['conversation_id'],
            (int) $row['author_id'],
            (string) $row['author_name'],
            (string) $row['body'],
            new \DateTimeImmutable($row['created_at']),
        );
    }
}
