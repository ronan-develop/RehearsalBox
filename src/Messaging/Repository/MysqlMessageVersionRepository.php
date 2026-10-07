<?php

declare(strict_types=1);

namespace App\Messaging\Repository;

use App\Messaging\Repository\MessageVersionRepositoryInterface;

final class MysqlMessageVersionRepository implements MessageVersionRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function versionsOf(int $messageId): array
    {
        $statement = $this->pdo->prepare('SELECT body, saved_at FROM conversation_message_versions WHERE message_id = :id ORDER BY id ASC');
        $statement->execute(['id' => $messageId]);

        return array_map(
            static fn (array $row): array => ['body' => (string) $row['body'], 'savedAt' => new \DateTimeImmutable($row['saved_at'])],
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    public function purgeBefore(\DateTimeImmutable $cutoff): int
    {
        $statement = $this->pdo->prepare('DELETE FROM conversation_message_versions WHERE saved_at < :cutoff');
        $statement->execute(['cutoff' => $cutoff->format(ConversationSql::DATE_FORMAT)]);

        return $statement->rowCount();
    }
}
