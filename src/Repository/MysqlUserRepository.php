<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Repository\Contract\UserRepositoryInterface;

final class MysqlUserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function findById(int $id): ?User
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByEmail(string $email): ?User
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE email = :email');
        $statement->execute(['email' => $email]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function save(User $user): User
    {
        if ($user->id() === 0) {
            $statement = $this->pdo->prepare(
                'INSERT INTO users (email, password_hash, display_name, role, is_active, failed_login_attempts, locked_until)
                 VALUES (:email, :password_hash, :display_name, :role, :is_active, :failed_login_attempts, :locked_until)'
            );
            $statement->execute([
                'email' => $user->email(),
                'password_hash' => $user->passwordHash(),
                'display_name' => $user->displayName(),
                'role' => $user->role()->value,
                'is_active' => (int) $user->isActive(),
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ]);

            return $this->findById((int) $this->pdo->lastInsertId());
        }

        $statement = $this->pdo->prepare(
            'UPDATE users SET email = :email, password_hash = :password_hash, display_name = :display_name,
             role = :role, is_active = :is_active WHERE id = :id'
        );
        $statement->execute([
            'id' => $user->id(),
            'email' => $user->email(),
            'password_hash' => $user->passwordHash(),
            'display_name' => $user->displayName(),
            'role' => $user->role()->value,
            'is_active' => (int) $user->isActive(),
        ]);

        return $this->findById($user->id());
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): User
    {
        return new User(
            id: (int) $row['id'],
            email: (string) $row['email'],
            passwordHash: (string) $row['password_hash'],
            displayName: (string) $row['display_name'],
            role: UserRole::from((string) $row['role']),
            isActive: (bool) $row['is_active'],
            failedLoginAttempts: (int) $row['failed_login_attempts'],
            lockedUntil: $row['locked_until'] !== null ? new \DateTimeImmutable((string) $row['locked_until']) : null,
        );
    }
}
