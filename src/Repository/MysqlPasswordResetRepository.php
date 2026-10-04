<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Contract\PasswordResetRepositoryInterface;

final class MysqlPasswordResetRepository implements PasswordResetRepositoryInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function create(int $userId, string $tokenHash, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now, string $purpose = self::PURPOSE_RESET): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO password_resets (user_id, purpose, token_hash, expires_at, created_at)
             VALUES (:user_id, :purpose, :token_hash, :expires_at, :created_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt->format(self::DATE_FORMAT),
            'created_at' => $now->format(self::DATE_FORMAT),
        ]);
    }

    public function invalidateAllForUser(int $userId, \DateTimeImmutable $now, string $purpose = self::PURPOSE_RESET): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE password_resets SET used_at = :now WHERE user_id = :user_id AND purpose = :purpose AND used_at IS NULL'
        );
        $statement->execute(['now' => $now->format(self::DATE_FORMAT), 'user_id' => $userId, 'purpose' => $purpose]);
    }

    public function countCreatedSince(int $userId, \DateTimeImmutable $since, string $purpose = self::PURPOSE_RESET): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM password_resets WHERE user_id = :user_id AND purpose = :purpose AND created_at >= :since'
        );
        $statement->execute(['user_id' => $userId, 'purpose' => $purpose, 'since' => $since->format(self::DATE_FORMAT)]);

        return (int) $statement->fetchColumn();
    }

    public function consume(string $tokenHash, \DateTimeImmutable $now, string $purpose = self::PURPOSE_RESET): ?int
    {
        // Un seul UPDATE conditionnel (jamais SELECT puis UPDATE) : deux requêtes
        // concurrentes sur le même jeton ne peuvent pas toutes les deux le consommer.
        $statement = $this->pdo->prepare(
            'UPDATE password_resets SET used_at = :used_at
             WHERE token_hash = :token_hash AND purpose = :purpose AND used_at IS NULL AND expires_at > :now'
        );
        $statement->execute([
            'used_at' => $now->format(self::DATE_FORMAT),
            'now' => $now->format(self::DATE_FORMAT),
            'token_hash' => $tokenHash,
            'purpose' => $purpose,
        ]);

        if ($statement->rowCount() === 0) {
            return null;
        }

        $select = $this->pdo->prepare('SELECT user_id FROM password_resets WHERE token_hash = :token_hash');
        $select->execute(['token_hash' => $tokenHash]);

        return (int) $select->fetchColumn();
    }
}
