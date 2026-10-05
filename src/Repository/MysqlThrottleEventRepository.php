<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Contract\ThrottleEventRepositoryInterface;

final class MysqlThrottleEventRepository implements ThrottleEventRepositoryInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function record(string $subjectHash, \DateTimeImmutable $occurredAt): void
    {
        $this->pdo->prepare('INSERT INTO throttle_events (subject_hash, occurred_at) VALUES (:subject_hash, :occurred_at)')
            ->execute(['subject_hash' => $subjectHash, 'occurred_at' => $occurredAt->format(self::DATE_FORMAT)]);
    }

    public function countSince(string $subjectHash, \DateTimeImmutable $since): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM throttle_events WHERE subject_hash = :subject_hash AND occurred_at >= :since');
        $statement->execute(['subject_hash' => $subjectHash, 'since' => $since->format(self::DATE_FORMAT)]);

        return (int) $statement->fetchColumn();
    }

    public function purgeBefore(\DateTimeImmutable $before): void
    {
        $this->pdo->prepare('DELETE FROM throttle_events WHERE occurred_at < :before')->execute(['before' => $before->format(self::DATE_FORMAT)]);
    }
}
