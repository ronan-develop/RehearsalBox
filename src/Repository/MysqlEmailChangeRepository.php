<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Contract\EmailChangeRepositoryInterface;

final class MysqlEmailChangeRepository implements EmailChangeRepositoryInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function create(int $userId, string $newEmail, string $tokenHash, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO email_changes (user_id, new_email, token_hash, expires_at, created_at)
             VALUES (:user_id, :new_email, :token_hash, :expires_at, :created_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'new_email' => $newEmail,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt->format(self::DATE_FORMAT),
            'created_at' => $now->format(self::DATE_FORMAT),
        ]);
    }

    public function invalidateAllForUser(int $userId, \DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare('UPDATE email_changes SET used_at = :now WHERE user_id = :user_id AND used_at IS NULL');
        $statement->execute(['now' => $now->format(self::DATE_FORMAT), 'user_id' => $userId]);
    }

    public function countCreatedSince(int $userId, \DateTimeImmutable $since): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM email_changes WHERE user_id = :user_id AND created_at >= :since');
        $statement->execute(['user_id' => $userId, 'since' => $since->format(self::DATE_FORMAT)]);

        return (int) $statement->fetchColumn();
    }

    public function consume(string $tokenHash, \DateTimeImmutable $now): ?array
    {
        // Un seul UPDATE conditionnel (jamais SELECT puis UPDATE) : deux requêtes concurrentes
        // sur le même jeton ne peuvent pas toutes les deux le consommer.
        $statement = $this->pdo->prepare(
            'UPDATE email_changes SET used_at = :used_at
             WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > :now'
        );
        $statement->execute([
            'used_at' => $now->format(self::DATE_FORMAT),
            'now' => $now->format(self::DATE_FORMAT),
            'token_hash' => $tokenHash,
        ]);

        if ($statement->rowCount() === 0) {
            return null;
        }

        $select = $this->pdo->prepare('SELECT user_id, new_email FROM email_changes WHERE token_hash = :token_hash');
        $select->execute(['token_hash' => $tokenHash]);
        $row = $select->fetch(\PDO::FETCH_ASSOC);

        return ['userId' => (int) $row['user_id'], 'newEmail' => (string) $row['new_email']];
    }
}
