<?php

declare(strict_types=1);

namespace App\Messaging\Repository\Participation;

use App\Messaging\Entity\ConversationAlert;
use App\Messaging\Repository\Participation\ConversationAlertRepositoryInterface;

final class MysqlConversationAlertRepository implements ConversationAlertRepositoryInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function notifyParticipants(int $conversationId, int $exceptUserId, string $kind, \DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO conversation_alerts (user_id, conversation_id, kind, label, created_at)
             SELECT DISTINCT gu.user_id, c.id, :kind, CONCAT(gi.name, ' ↔ ', gt.name), :now
             FROM conversations c
             JOIN `groups` gi ON gi.id = c.initiator_group_id
             JOIN `groups` gt ON gt.id = c.target_group_id
             JOIN group_user gu ON gu.group_id IN (c.initiator_group_id, c.target_group_id)
             WHERE c.id = :conversation_id AND gu.user_id <> :except_user"
        );
        $statement->execute([
            'kind' => $kind,
            'now' => $now->format(self::DATE_FORMAT),
            'conversation_id' => $conversationId,
            'except_user' => $exceptUserId,
        ]);

        // Les invités (#178) sont prévenus aussi, sauf s'ils sont déjà membres d'un des deux groupes (déjà avertis ci-dessus).
        $guests = $this->pdo->prepare(
            "INSERT INTO conversation_alerts (user_id, conversation_id, kind, label, created_at)
             SELECT cg.user_id, c.id, :kind, CONCAT(gi.name, ' ↔ ', gt.name), :now
             FROM conversation_guests cg
             JOIN conversations c ON c.id = cg.conversation_id
             JOIN `groups` gi ON gi.id = c.initiator_group_id
             JOIN `groups` gt ON gt.id = c.target_group_id
             WHERE cg.conversation_id = :conversation_id AND cg.user_id <> :except_user
               AND NOT EXISTS (SELECT 1 FROM group_user gu WHERE gu.user_id = cg.user_id AND gu.group_id IN (c.initiator_group_id, c.target_group_id))"
        );
        $guests->execute([
            'kind' => $kind,
            'now' => $now->format(self::DATE_FORMAT),
            'conversation_id' => $conversationId,
            'except_user' => $exceptUserId,
        ]);
    }

    public function findActiveFor(int $userId, \DateTimeImmutable $since): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, conversation_id, kind, label, created_at FROM conversation_alerts
             WHERE user_id = :user_id AND dismissed_at IS NULL AND created_at >= :since
             ORDER BY created_at DESC, id DESC'
        );
        $statement->execute(['user_id' => $userId, 'since' => $since->format(self::DATE_FORMAT)]);

        return array_map(
            static fn (array $row): ConversationAlert => new ConversationAlert(
                (int) $row['id'],
                $row['conversation_id'] === null ? null : (int) $row['conversation_id'],
                (string) $row['kind'],
                (string) $row['label'],
                new \DateTimeImmutable($row['created_at']),
            ),
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    public function countActiveFor(int $userId, \DateTimeImmutable $since): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM conversation_alerts WHERE user_id = :user_id AND dismissed_at IS NULL AND created_at >= :since');
        $statement->execute(['user_id' => $userId, 'since' => $since->format(self::DATE_FORMAT)]);

        return (int) $statement->fetchColumn();
    }

    public function dismiss(int $alertId, int $userId, \DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare('UPDATE conversation_alerts SET dismissed_at = :now WHERE id = :id AND user_id = :user_id AND dismissed_at IS NULL');
        $statement->execute(['now' => $now->format(self::DATE_FORMAT), 'id' => $alertId, 'user_id' => $userId]);
    }
}
