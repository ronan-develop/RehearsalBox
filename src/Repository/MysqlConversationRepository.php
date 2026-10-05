<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Repository\Contract\ConversationRepositoryInterface;

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

    public function rename(int $conversationId, ?string $title): void
    {
        $statement = $this->pdo->prepare('UPDATE conversations SET title = :title WHERE id = :id');
        $statement->execute(['title' => $title, 'id' => $conversationId]);
    }

    public function addMessage(int $conversationId, int $authorId, string $body, \DateTimeImmutable $now, bool $system = false): ConversationMessage
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO conversation_messages (conversation_id, author_id, body, is_system, created_at)
             VALUES (:conversation_id, :author_id, :body, :is_system, :created_at)'
        );
        $statement->execute([
            'conversation_id' => $conversationId,
            'author_id' => $authorId,
            'body' => $body,
            'is_system' => (int) $system,
            'created_at' => $now->format(self::DATE_FORMAT),
        ]);

        // Lu AVANT toute autre requête : lastInsertId() renvoie 0 après un SELECT.
        $id = (int) $this->pdo->lastInsertId();

        $name = $this->pdo->prepare('SELECT display_name FROM users WHERE id = :id');
        $name->execute(['id' => $authorId]);

        return new ConversationMessage($id, $conversationId, $authorId, (string) $name->fetchColumn(), $body, $now, $system);
    }

    public function messageById(int $conversationId, int $messageId): ?ConversationMessage
    {
        $statement = $this->pdo->prepare(
            'SELECT m.id, m.author_id, u.display_name AS author_name, m.body, m.is_system, m.created_at, m.edited_at
             FROM conversation_messages m JOIN users u ON u.id = m.author_id
             WHERE m.conversation_id = :conversation_id AND m.id = :id'
        );
        $statement->execute(['conversation_id' => $conversationId, 'id' => $messageId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : ConversationRows::message($row, $conversationId);
    }

    public function lastMessageBy(int $conversationId, int $authorId): ?ConversationMessage
    {
        $statement = $this->pdo->prepare(
            'SELECT m.id, m.author_id, u.display_name AS author_name, m.body, m.is_system, m.created_at
             FROM conversation_messages m JOIN users u ON u.id = m.author_id
             WHERE m.conversation_id = :conversation_id AND m.author_id = :author_id AND m.is_system = 0
             ORDER BY m.created_at DESC, m.id DESC LIMIT 1'
        );
        $statement->execute(['conversation_id' => $conversationId, 'author_id' => $authorId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : ConversationRows::message($row, $conversationId);
    }

    public function findById(int $id): ?Conversation
    {
        $statement = $this->pdo->prepare(
            'SELECT id, initiator_group_id, target_group_id, created_by, title, created_at, deleted_at FROM conversations WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : ConversationRows::conversation($row);
    }

    public function messagesOf(int $conversationId, int $afterId = 0): array
    {
        $statement = $this->pdo->prepare(
            'SELECT m.id, m.author_id, u.display_name AS author_name, m.body, m.is_system, m.created_at, m.edited_at
             FROM conversation_messages m JOIN users u ON u.id = m.author_id
             WHERE m.conversation_id = :conversation_id AND m.id > :after_id
             ORDER BY m.created_at ASC, m.id ASC'
        );
        $statement->execute(['conversation_id' => $conversationId, 'after_id' => $afterId]);

        return array_map(fn (array $row): ConversationMessage => ConversationRows::message($row, $conversationId), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function listFor(int $userId, string $box, \DateTimeImmutable $inactiveBefore): array
    {
        $sql = 'SELECT c.id, c.initiator_group_id, c.target_group_id, c.created_by, c.title, c.created_at, c.deleted_at,
                       gi.name AS initiator_name, gt.name AS target_name,
                       lm.id AS last_id, lm.author_id AS last_author_id, lu.display_name AS last_author_name,
                       lm.body AS last_body, lm.is_system AS last_is_system, lm.created_at AS last_created_at,
                       ' . ConversationSql::UNREAD_FOR_USER . ' AS unread,
                       ' . ConversationSql::MENTIONED_USER . ' AS mentioned
                FROM conversations c
                JOIN `groups` gi ON gi.id = c.initiator_group_id
                JOIN `groups` gt ON gt.id = c.target_group_id
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
            'cutoff' => $inactiveBefore->format(self::DATE_FORMAT),
        ]);

        return array_map(ConversationRows::summary(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function lastReadAt(int $conversationId, int $userId): ?\DateTimeImmutable
    {
        $statement = $this->pdo->prepare('SELECT last_read_at FROM conversation_states WHERE conversation_id = :conversation_id AND user_id = :user_id');
        $statement->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : new \DateTimeImmutable((string) $value);
    }

    public function markRead(int $conversationId, int $userId, \DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO conversation_states (conversation_id, user_id, last_read_at) VALUES (:conversation_id, :user_id, :now)
             ON DUPLICATE KEY UPDATE last_read_at = VALUES(last_read_at)'
        );
        $statement->execute(['conversation_id' => $conversationId, 'user_id' => $userId, 'now' => $now->format(self::DATE_FORMAT)]);
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

    public function countMessagesBySince(int $authorId, \DateTimeImmutable $since): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM conversation_messages WHERE author_id = :author_id AND created_at >= :since');
        $statement->execute(['author_id' => $authorId, 'since' => $since->format(self::DATE_FORMAT)]);

        return (int) $statement->fetchColumn();
    }

    public function setTyping(int $conversationId, int $userId, \DateTimeImmutable $now): void
    {
        // Atomique et sans lecture préalable : un signal moins de 2 s après le précédent est ignoré.
        $statement = $this->pdo->prepare(
            'INSERT INTO conversation_states (conversation_id, user_id, typing_at) VALUES (:conversation_id, :user_id, :now)
             ON DUPLICATE KEY UPDATE typing_at = IF(typing_at IS NULL OR typing_at <= :min_previous, VALUES(typing_at), typing_at)'
        );
        $statement->execute([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'now' => $now->format(self::DATE_FORMAT),
            'min_previous' => $now->modify('-2 seconds')->format(self::DATE_FORMAT),
        ]);
    }

    public function typingNames(int $conversationId, int $exceptUserId, \DateTimeImmutable $since): array
    {
        return $this->memberNames('s.typing_at', $conversationId, $exceptUserId, $since);
    }

    public function readersOf(int $conversationId, \DateTimeImmutable $messageDate, int $exceptUserId): array
    {
        return $this->memberNames('s.last_read_at', $conversationId, $exceptUserId, $messageDate);
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
             ) participants'
        );
        $statement->execute(['conversation_id' => $conversationId, 'guest_conversation_id' => $conversationId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Noms des membres (de l'un des deux groupes), hors $exceptUserId, dont la colonne d'état $column est
     * postérieure ou égale à $since. Un membre parti d'un groupe n'apparaît plus.
     *
     * @param 's.typing_at'|'s.last_read_at' $column colonne choisie par le code (jamais par l'utilisateur)
     *
     * @return list<string>
     */
    private function memberNames(string $column, int $conversationId, int $exceptUserId, \DateTimeImmutable $since): array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.display_name
             FROM conversation_states s
             JOIN conversations c ON c.id = s.conversation_id
             JOIN users u ON u.id = s.user_id
             WHERE s.conversation_id = :conversation_id AND s.user_id <> :except_user AND ' . $column . ' >= :since
               AND ' . ConversationSql::visibleTo('s.user_id') . '
             ORDER BY u.display_name, u.id'
        );
        $statement->execute([
            'conversation_id' => $conversationId,
            'except_user' => $exceptUserId,
            'since' => $since->format(self::DATE_FORMAT),
        ]);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function updateBody(int $messageId, string $body, \DateTimeImmutable $now): void
    {
        $save = $this->pdo->prepare('INSERT INTO conversation_message_versions (message_id, body, saved_at) SELECT id, body, :now FROM conversation_messages WHERE id = :id');
        $save->execute(['now' => $now->format(self::DATE_FORMAT), 'id' => $messageId]);

        $update = $this->pdo->prepare('UPDATE conversation_messages SET body = :body, edited_at = :now WHERE id = :id');
        $update->execute(['body' => $body, 'now' => $now->format(self::DATE_FORMAT), 'id' => $messageId]);
    }

    public function editedSince(int $conversationId, \DateTimeImmutable $since, int $upToMessageId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT m.id, m.author_id, u.display_name AS author_name, m.body, m.is_system, m.created_at, m.edited_at
             FROM conversation_messages m JOIN users u ON u.id = m.author_id
             WHERE m.conversation_id = :conversation_id AND m.id <= :up_to AND m.edited_at > :since
             ORDER BY m.edited_at ASC, m.id ASC'
        );
        $statement->execute(['conversation_id' => $conversationId, 'up_to' => $upToMessageId, 'since' => $since->format(self::DATE_FORMAT)]);

        return array_map(fn (array $row): ConversationMessage => ConversationRows::message($row, $conversationId), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function countEditsBySince(int $authorId, \DateTimeImmutable $since): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM conversation_message_versions v JOIN conversation_messages m ON m.id = v.message_id
             WHERE m.author_id = :author_id AND v.saved_at >= :since'
        );
        $statement->execute(['author_id' => $authorId, 'since' => $since->format(self::DATE_FORMAT)]);

        return (int) $statement->fetchColumn();
    }

    /** @return list<array{body: string, savedAt: \DateTimeImmutable}> */
    public function versionsOf(int $messageId): array
    {
        $statement = $this->pdo->prepare('SELECT body, saved_at FROM conversation_message_versions WHERE message_id = :id ORDER BY id ASC');
        $statement->execute(['id' => $messageId]);

        return array_map(
            static fn (array $row): array => ['body' => (string) $row['body'], 'savedAt' => new \DateTimeImmutable($row['saved_at'])],
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        );
    }
}
