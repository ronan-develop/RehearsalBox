<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Contract\LoginFailureRepositoryInterface;

final class MysqlLoginFailureRepository implements LoginFailureRepositoryInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function record(string $ipHash, \DateTimeImmutable $failedAt): void
    {
        $this->pdo->prepare('INSERT INTO login_failures (ip_hash, failed_at) VALUES (:ip_hash, :failed_at)')
            ->execute(['ip_hash' => $ipHash, 'failed_at' => $failedAt->format(self::DATE_FORMAT)]);
    }

    public function countSince(string $ipHash, \DateTimeImmutable $since): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM login_failures WHERE ip_hash = :ip_hash AND failed_at >= :since');
        $statement->execute(['ip_hash' => $ipHash, 'since' => $since->format(self::DATE_FORMAT)]);

        return (int) $statement->fetchColumn();
    }

    public function purgeBefore(\DateTimeImmutable $before): void
    {
        $this->pdo->prepare('DELETE FROM login_failures WHERE failed_at < :before')->execute(['before' => $before->format(self::DATE_FORMAT)]);
    }
}
