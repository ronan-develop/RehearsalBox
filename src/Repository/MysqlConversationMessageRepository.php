<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConversationMessage;
use App\Repository\Contract\ConversationMessageRepositoryInterface;

final class MysqlConversationMessageRepository implements ConversationMessageRepositoryInterface
{
    private const DATE_FORMAT = ConversationSql::DATE_FORMAT;

    // Un message avec son auteur et, s'il en cite un (#214), l'auteur et le texte ACTUELS du message cité.
    private const SELECT_MESSAGE = 'SELECT m.id, m.author_id, u.display_name AS author_name, m.body, m.is_system, m.created_at, m.edited_at,
                q.id AS quote_id, qu.display_name AS quote_author, q.body AS quote_body
         FROM conversation_messages m
         JOIN users u ON u.id = m.author_id
         LEFT JOIN conversation_messages q ON q.id = m.reply_to_message_id
         LEFT JOIN users qu ON qu.id = q.author_id';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function addMessage(int $conversationId, int $authorId, string $body, \DateTimeImmutable $now, bool $system = false, ?int $replyToId = null): ConversationMessage
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO conversation_messages (conversation_id, author_id, body, is_system, reply_to_message_id, created_at)
             VALUES (:conversation_id, :author_id, :body, :is_system, :reply_to, :created_at)'
        );
        $statement->execute([
            'conversation_id' => $conversationId,
            'author_id' => $authorId,
            'body' => $body,
            'is_system' => (int) $system,
            'reply_to' => $replyToId,
            'created_at' => $now->format(self::DATE_FORMAT),
        ]);

        // Lu AVANT toute autre requête : lastInsertId() renvoie 0 après un SELECT.
        $id = (int) $this->pdo->lastInsertId();

        if ($replyToId !== null) {
            return $this->messageById($conversationId, $id) ?? throw new \LogicException('Message introuvable après insertion.');
        }

        $name = $this->pdo->prepare('SELECT display_name FROM users WHERE id = :id');
        $name->execute(['id' => $authorId]);

        return new ConversationMessage($id, $conversationId, $authorId, (string) $name->fetchColumn(), $body, $now, $system);
    }

    public function messageById(int $conversationId, int $messageId): ?ConversationMessage
    {
        $statement = $this->pdo->prepare(
            self::SELECT_MESSAGE . '
             WHERE m.conversation_id = :conversation_id AND m.id = :id'
        );
        $statement->execute(['conversation_id' => $conversationId, 'id' => $messageId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : ConversationRows::message($row, $conversationId);
    }

    public function lastMessageBy(int $conversationId, int $authorId): ?ConversationMessage
    {
        $statement = $this->pdo->prepare(
            self::SELECT_MESSAGE . '
             WHERE m.conversation_id = :conversation_id AND m.author_id = :author_id AND m.is_system = 0
             ORDER BY m.created_at DESC, m.id DESC LIMIT 1'
        );
        $statement->execute(['conversation_id' => $conversationId, 'author_id' => $authorId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : ConversationRows::message($row, $conversationId);
    }

    public function messagesOf(int $conversationId, int $afterId = 0): array
    {
        $statement = $this->pdo->prepare(
            self::SELECT_MESSAGE . '
             WHERE m.conversation_id = :conversation_id AND m.id > :after_id
             ORDER BY m.created_at ASC, m.id ASC'
        );
        $statement->execute(['conversation_id' => $conversationId, 'after_id' => $afterId]);

        return array_map(fn (array $row): ConversationMessage => ConversationRows::message($row, $conversationId), $statement->fetchAll(\PDO::FETCH_ASSOC));
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
            self::SELECT_MESSAGE . '
             WHERE m.conversation_id = :conversation_id AND m.id <= :up_to AND m.edited_at > :since
             ORDER BY m.edited_at ASC, m.id ASC'
        );
        $statement->execute(['conversation_id' => $conversationId, 'up_to' => $upToMessageId, 'since' => $since->format(self::DATE_FORMAT)]);

        return array_map(fn (array $row): ConversationMessage => ConversationRows::message($row, $conversationId), $statement->fetchAll(\PDO::FETCH_ASSOC));
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

    public function countMessagesBySince(int $authorId, \DateTimeImmutable $since): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM conversation_messages WHERE author_id = :author_id AND created_at >= :since');
        $statement->execute(['author_id' => $authorId, 'since' => $since->format(self::DATE_FORMAT)]);

        return (int) $statement->fetchColumn();
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
}
