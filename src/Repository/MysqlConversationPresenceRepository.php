<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Contract\ConversationPresenceRepositoryInterface;

final class MysqlConversationPresenceRepository implements ConversationPresenceRepositoryInterface
{
    private const DATE_FORMAT = ConversationSql::DATE_FORMAT;

    public function __construct(private readonly \PDO $pdo)
    {
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
}
