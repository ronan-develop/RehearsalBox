<?php

declare(strict_types=1);

namespace App\Messaging\Repository\Notice;

use App\Messaging\Entity\DueMentionReminder;
use App\Messaging\Entity\MentionNotice;
use App\Messaging\Repository\Notice\MentionNoticeRepositoryInterface;

final class MysqlMentionNoticeRepository implements MentionNoticeRepositoryInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function claimNotice(int $conversationId, int $userId, int $byUserId, \DateTimeImmutable $now, \DateTimeImmutable $notBefore): bool
    {
        // Les affectations s'évaluent de gauche à droite : la relance et l'auteur sont décidés d'après l'ANCIENNE date,
        // la date est mise à jour en dernier. Aucune ligne modifiée = un e-mail est parti il y a moins de 24 h.
        $statement = $this->pdo->prepare(
            'INSERT INTO conversation_mention_notices (conversation_id, user_id, notified_at, notified_by, reminded_at)
             VALUES (:conversation_id, :user_id, :now, :by, NULL)
             ON DUPLICATE KEY UPDATE
                 reminded_at = IF(notified_at <= :not_before_reminder, NULL, reminded_at),
                 notified_by = IF(notified_at <= :not_before_by, VALUES(notified_by), notified_by),
                 notified_at = IF(notified_at <= :not_before_at, VALUES(notified_at), notified_at)'
        );
        $limit = $notBefore->format(self::DATE_FORMAT);
        $statement->execute([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'now' => $now->format(self::DATE_FORMAT),
            'by' => $byUserId,
            'not_before_reminder' => $limit,
            'not_before_by' => $limit,
            'not_before_at' => $limit,
        ]);

        return $statement->rowCount() > 0;
    }

    public function find(int $conversationId, int $userId): ?MentionNotice
    {
        $statement = $this->pdo->prepare('SELECT notified_at, notified_by, reminded_at FROM conversation_mention_notices WHERE conversation_id = :conversation_id AND user_id = :user_id');
        $statement->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : new MentionNotice(
            new \DateTimeImmutable($row['notified_at']),
            $row['notified_by'] === null ? null : (int) $row['notified_by'],
            $row['reminded_at'] === null ? null : new \DateTimeImmutable($row['reminded_at']),
        );
    }

    public function restoreNotice(int $conversationId, int $userId, ?MentionNotice $previous): void
    {
        if ($previous === null) {
            $statement = $this->pdo->prepare('DELETE FROM conversation_mention_notices WHERE conversation_id = :conversation_id AND user_id = :user_id');
            $statement->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);

            return;
        }
        $statement = $this->pdo->prepare(
            'UPDATE conversation_mention_notices SET notified_at = :notified_at, notified_by = :by, reminded_at = :reminded_at
             WHERE conversation_id = :conversation_id AND user_id = :user_id'
        );
        $statement->execute([
            'notified_at' => $previous->notifiedAt()->format(self::DATE_FORMAT),
            'by' => $previous->notifiedBy(),
            'reminded_at' => $previous->remindedAt()?->format(self::DATE_FORMAT),
            'conversation_id' => $conversationId,
            'user_id' => $userId,
        ]);
    }

    public function countSentBy(int $byUserId, \DateTimeImmutable $since): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM conversation_mention_notices WHERE notified_by = :by AND notified_at >= :since');
        $statement->execute(['by' => $byUserId, 'since' => $since->format(self::DATE_FORMAT)]);

        return (int) $statement->fetchColumn();
    }

    public function findDueReminders(\DateTimeImmutable $dueBefore, \DateTimeImmutable $notBefore): array
    {
        $statement = $this->pdo->prepare(
            "SELECT n.conversation_id, n.user_id, u.email, COALESCE(mu.display_name, '') AS mentioner, (c.direct_low_user_id IS NOT NULL) AS is_direct
             FROM conversation_mention_notices n
             JOIN conversations c ON c.id = n.conversation_id
             JOIN users u ON u.id = n.user_id
             LEFT JOIN users mu ON mu.id = n.notified_by
             WHERE n.reminded_at IS NULL AND n.notified_at <= :due_before AND n.notified_at >= :not_before
               AND c.deleted_at IS NULL AND u.is_active = 1
               AND (
                   n.user_id IN (c.direct_low_user_id, c.direct_high_user_id)
                   OR n.user_id IN (
                       SELECT gu.user_id FROM group_user gu WHERE gu.group_id IN (c.initiator_group_id, c.target_group_id)
                       UNION
                       SELECT cg.user_id FROM conversation_guests cg WHERE cg.conversation_id = c.id
                   )
               )
               AND NOT EXISTS (
                   SELECT 1 FROM conversation_states ms
                   WHERE ms.conversation_id = n.conversation_id AND ms.user_id = n.user_id AND ms.muted = 1
               )
               AND NOT EXISTS (
                   SELECT 1 FROM conversation_states s
                   WHERE s.conversation_id = n.conversation_id AND s.user_id = n.user_id
                     AND s.last_read_at >= COALESCE((
                         SELECT MAX(m.created_at) FROM message_mentions mm JOIN conversation_messages m ON m.id = mm.message_id
                         WHERE m.conversation_id = n.conversation_id AND mm.user_id = n.user_id
                     ), n.notified_at)
               )
             ORDER BY n.notified_at, n.conversation_id, n.user_id"
        );
        $statement->execute([
            'due_before' => $dueBefore->format(self::DATE_FORMAT),
            'not_before' => $notBefore->format(self::DATE_FORMAT),
        ]);

        return array_map(
            static fn (array $row): DueMentionReminder => new DueMentionReminder((int) $row['conversation_id'], (int) $row['user_id'], (string) $row['email'], (string) $row['mentioner'], (bool) $row['is_direct']),
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    public function claimReminder(int $conversationId, int $userId, \DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare('UPDATE conversation_mention_notices SET reminded_at = :now WHERE conversation_id = :conversation_id AND user_id = :user_id AND reminded_at IS NULL');
        $statement->execute(['now' => $now->format(self::DATE_FORMAT), 'conversation_id' => $conversationId, 'user_id' => $userId]);

        return $statement->rowCount() === 1;
    }

    public function restoreReminder(int $conversationId, int $userId): void
    {
        $statement = $this->pdo->prepare('UPDATE conversation_mention_notices SET reminded_at = NULL WHERE conversation_id = :conversation_id AND user_id = :user_id');
        $statement->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);
    }
}
