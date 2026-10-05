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

    // Dernier message du fil : sert à l'aperçu, au tri et à l'archivage dérivé (inactivité).
    private const LAST_MESSAGE_JOIN = 'JOIN conversation_messages lm ON lm.id = (
        SELECT MAX(x.id) FROM conversation_messages x WHERE x.conversation_id = c.id
    )';

    private const UNREAD_FOR_USER = 'EXISTS (
        SELECT 1 FROM conversation_messages um
        WHERE um.conversation_id = c.id AND um.author_id <> :unread_user
          AND (s.last_read_at IS NULL OR um.created_at > s.last_read_at)
    )';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function create(int $initiatorGroupId, int $targetGroupId, ?string $title, \DateTimeImmutable $now): Conversation
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO conversations (initiator_group_id, target_group_id, title, created_at)
             VALUES (:initiator, :target, :title, :created_at)'
        );
        $statement->execute([
            'initiator' => $initiatorGroupId,
            'target' => $targetGroupId,
            'title' => $title,
            'created_at' => $now->format(self::DATE_FORMAT),
        ]);

        return new Conversation((int) $this->pdo->lastInsertId(), $initiatorGroupId, $targetGroupId, $title, $now);
    }

    public function rename(int $conversationId, ?string $title): void
    {
        $statement = $this->pdo->prepare('UPDATE conversations SET title = :title WHERE id = :id');
        $statement->execute(['title' => $title, 'id' => $conversationId]);
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

        // Lu AVANT toute autre requête : lastInsertId() renvoie 0 après un SELECT.
        $id = (int) $this->pdo->lastInsertId();

        $name = $this->pdo->prepare('SELECT display_name FROM users WHERE id = :id');
        $name->execute(['id' => $authorId]);

        return new ConversationMessage($id, $conversationId, $authorId, (string) $name->fetchColumn(), $body, $now);
    }

    public function findById(int $id): ?Conversation
    {
        $statement = $this->pdo->prepare(
            'SELECT id, initiator_group_id, target_group_id, title, created_at FROM conversations WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrateConversation($row);
    }

    public function messagesOf(int $conversationId, int $afterId = 0): array
    {
        $statement = $this->pdo->prepare(
            'SELECT m.id, m.author_id, u.display_name AS author_name, m.body, m.created_at
             FROM conversation_messages m JOIN users u ON u.id = m.author_id
             WHERE m.conversation_id = :conversation_id AND m.id > :after_id
             ORDER BY m.created_at ASC, m.id ASC'
        );
        $statement->execute(['conversation_id' => $conversationId, 'after_id' => $afterId]);

        return array_map(fn (array $row): ConversationMessage => $this->hydrateMessage($row, $conversationId), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function listFor(int $userId, string $box, \DateTimeImmutable $inactiveBefore): array
    {
        $sql = 'SELECT c.id, c.initiator_group_id, c.target_group_id, c.title, c.created_at,
                       gi.name AS initiator_name, gt.name AS target_name,
                       lm.id AS last_id, lm.author_id AS last_author_id, lu.display_name AS last_author_name,
                       lm.body AS last_body, lm.created_at AS last_created_at,
                       ' . self::UNREAD_FOR_USER . ' AS unread
                FROM conversations c
                JOIN `groups` gi ON gi.id = c.initiator_group_id
                JOIN `groups` gt ON gt.id = c.target_group_id
                ' . self::LAST_MESSAGE_JOIN . '
                JOIN users lu ON lu.id = lm.author_id
                LEFT JOIN conversation_states s ON s.conversation_id = c.id AND s.user_id = :state_user
                WHERE ' . $this->visibleTo(':visible_user') . ' AND ' . $this->boxCondition($box) . '
                ORDER BY lm.created_at DESC, lm.id DESC';

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'unread_user' => $userId,
            'state_user' => $userId,
            'visible_user' => $userId,
            'cutoff' => $inactiveBefore->format(self::DATE_FORMAT),
        ]);

        return array_map(
            fn (array $row): ConversationSummary => new ConversationSummary(
                $this->hydrateConversation($row),
                (string) $row['initiator_name'],
                (string) $row['target_name'],
                $this->hydrateMessage($row, (int) $row['id'], 'last_'),
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

    public function countUnreadFor(int $userId, \DateTimeImmutable $inactiveBefore, ?string $box = null): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM conversations c
             ' . self::LAST_MESSAGE_JOIN . '
             LEFT JOIN conversation_states s ON s.conversation_id = c.id AND s.user_id = :state_user
             WHERE ' . $this->visibleTo(':visible_user') . ' AND ' . ($box === null ? '1 = 1' : $this->boxCondition($box)) . ' AND ' . self::UNREAD_FOR_USER
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
        $statement = $this->pdo->prepare(
            'INSERT INTO conversation_states (conversation_id, user_id, typing_at) VALUES (:conversation_id, :user_id, :now)
             ON DUPLICATE KEY UPDATE typing_at = VALUES(typing_at)'
        );
        $statement->execute(['conversation_id' => $conversationId, 'user_id' => $userId, 'now' => $now->format(self::DATE_FORMAT)]);
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
            'SELECT COUNT(DISTINCT gu.user_id) FROM conversations c
             JOIN group_user gu ON gu.group_id IN (c.initiator_group_id, c.target_group_id)
             WHERE c.id = :conversation_id'
        );
        $statement->execute(['conversation_id' => $conversationId]);

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
               AND ' . $this->visibleTo('s.user_id') . '
             ORDER BY u.display_name, u.id'
        );
        $statement->execute([
            'conversation_id' => $conversationId,
            'except_user' => $exceptUserId,
            'since' => $since->format(self::DATE_FORMAT),
        ]);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Condition SQL : la personne désignée par $userExpr est membre de l'un des deux groupes de c (comptée une seule fois). */
    private function visibleTo(string $userExpr): string
    {
        return 'EXISTS (
            SELECT 1 FROM group_user gu
            WHERE gu.user_id = ' . $userExpr . ' AND gu.group_id IN (c.initiator_group_id, c.target_group_id)
        )';
    }

    /** Active = dernier message à partir de la limite d'inactivité (:cutoff incluse) ; archivée = antérieur. */
    private function boxCondition(string $box): string
    {
        return $box === self::BOX_ARCHIVED ? 'lm.created_at < :cutoff' : 'lm.created_at >= :cutoff';
    }

    /** @param array<string, mixed> $row */
    private function hydrateConversation(array $row): Conversation
    {
        return new Conversation(
            (int) $row['id'],
            (int) $row['initiator_group_id'],
            (int) $row['target_group_id'],
            $row['title'] === null ? null : (string) $row['title'],
            new \DateTimeImmutable($row['created_at']),
        );
    }

    /**
     * @param array<string, mixed> $row    colonnes id, author_id, author_name, body, created_at, éventuellement préfixées
     * @param string               $prefix préfixe des colonnes du message dans la ligne (ex. « last_ »)
     */
    private function hydrateMessage(array $row, int $conversationId, string $prefix = ''): ConversationMessage
    {
        return new ConversationMessage(
            (int) $row[$prefix . 'id'],
            $conversationId,
            (int) $row[$prefix . 'author_id'],
            (string) $row[$prefix . 'author_name'],
            (string) $row[$prefix . 'body'],
            new \DateTimeImmutable($row[$prefix . 'created_at']),
        );
    }
}
