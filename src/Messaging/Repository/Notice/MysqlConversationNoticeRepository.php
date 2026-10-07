<?php

declare(strict_types=1);

namespace App\Messaging\Repository\Notice;

use App\Messaging\Entity\DueReminder;
use App\Messaging\Repository\Notice\ConversationNoticeRepositoryInterface;

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

    public function countInitialSince(int $groupId, \DateTimeImmutable $since): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM conversation_group_notices WHERE group_id = :group_id AND notified_at >= :since');
        $statement->execute(['group_id' => $groupId, 'since' => $since->format(self::DATE_FORMAT)]);

        return (int) $statement->fetchColumn();
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

    public function findDueReminders(\DateTimeImmutable $dueBefore, \DateTimeImmutable $notBefore): array
    {
        // Pour chaque (conversation, groupe) : le plus ancien message de l'autre côté, ordinaire, dans la fenêtre, que
        // personne du groupe n'a lu depuis, et plus récent que le dernier rappel. L'auteur doit être membre du groupe
        // d'en face et PAS du groupe relancé (un membre des deux groupes, ou parti, ne déclenche rien).
        $statement = $this->pdo->prepare(
            'SELECT c.id AS conversation_id, g.id AS group_id, g.name AS group_name, g.contact_email AS contact_email,
                    og.name AS counterpart_name, MIN(m.created_at) AS since
             FROM conversations c
             JOIN `groups` g ON g.id IN (c.initiator_group_id, c.target_group_id)
             JOIN `groups` og ON og.id = IF(g.id = c.initiator_group_id, c.target_group_id, c.initiator_group_id)
             JOIN conversation_messages m ON m.conversation_id = c.id AND m.is_system = 0
             LEFT JOIN conversation_group_notices n ON n.conversation_id = c.id AND n.group_id = g.id
             WHERE c.deleted_at IS NULL AND m.created_at <= :due_before AND m.created_at >= :not_before
               AND (n.reminded_at IS NULL OR m.created_at > n.reminded_at)
               AND EXISTS (SELECT 1 FROM group_user go WHERE go.group_id = og.id AND go.user_id = m.author_id)
               AND NOT EXISTS (SELECT 1 FROM group_user ga WHERE ga.group_id = g.id AND ga.user_id = m.author_id)
               AND NOT EXISTS (
                   SELECT 1 FROM group_user gr
                   JOIN conversation_states s ON s.user_id = gr.user_id AND s.conversation_id = c.id
                   WHERE gr.group_id = g.id AND s.last_read_at >= m.created_at
               )
             GROUP BY c.id, g.id, g.name, g.contact_email, og.name
             ORDER BY since ASC, c.id ASC, g.id ASC'
        );
        $statement->execute([
            'due_before' => $dueBefore->format(self::DATE_FORMAT),
            'not_before' => $notBefore->format(self::DATE_FORMAT),
        ]);

        return array_map(
            static fn (array $row): DueReminder => new DueReminder(
                (int) $row['conversation_id'],
                (int) $row['group_id'],
                (string) $row['group_name'],
                (string) $row['contact_email'],
                (string) $row['counterpart_name'],
                new \DateTimeImmutable($row['since']),
            ),
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    public function remindedAt(int $conversationId, int $groupId): ?\DateTimeImmutable
    {
        $statement = $this->pdo->prepare('SELECT reminded_at FROM conversation_group_notices WHERE conversation_id = :conversation_id AND group_id = :group_id');
        $statement->execute(['conversation_id' => $conversationId, 'group_id' => $groupId]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : new \DateTimeImmutable((string) $value);
    }

    public function claimReminder(int $conversationId, int $groupId, \DateTimeImmutable $now): bool
    {
        // Atomique : créée ou mise à jour seulement si aucun rappel n'a été réservé dans l'heure (exécutions qui se chevauchent).
        $statement = $this->pdo->prepare(
            'INSERT INTO conversation_group_notices (conversation_id, group_id, reminded_at) VALUES (:conversation_id, :group_id, :now)
             ON DUPLICATE KEY UPDATE reminded_at = IF(reminded_at IS NULL OR reminded_at < :min_gap, VALUES(reminded_at), reminded_at)'
        );
        $statement->execute([
            'conversation_id' => $conversationId,
            'group_id' => $groupId,
            'now' => $now->format(self::DATE_FORMAT),
            'min_gap' => $now->modify('-1 hour')->format(self::DATE_FORMAT),
        ]);

        return $statement->rowCount() > 0;
    }

    public function restoreReminder(int $conversationId, int $groupId, ?\DateTimeImmutable $previous): void
    {
        $statement = $this->pdo->prepare('UPDATE conversation_group_notices SET reminded_at = :previous WHERE conversation_id = :conversation_id AND group_id = :group_id');
        $statement->execute([
            'previous' => $previous?->format(self::DATE_FORMAT),
            'conversation_id' => $conversationId,
            'group_id' => $groupId,
        ]);
    }
}
