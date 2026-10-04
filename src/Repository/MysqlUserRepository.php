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

    public function findAll(): array
    {
        $rows = $this->pdo->query('SELECT * FROM users ORDER BY display_name, id')->fetchAll(\PDO::FETCH_ASSOC);

        return array_map($this->hydrate(...), $rows);
    }

    public function countActiveAdmins(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE role = :role AND is_active = 1');
        $statement->execute(['role' => UserRole::Admin->value]);

        return (int) $statement->fetchColumn();
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
             role = :role, is_active = :is_active, failed_login_attempts = :failed_login_attempts,
             locked_until = :locked_until, session_version = :session_version WHERE id = :id'
        );
        $statement->execute([
            'id' => $user->id(),
            'email' => $user->email(),
            'password_hash' => $user->passwordHash(),
            'display_name' => $user->displayName(),
            'role' => $user->role()->value,
            'is_active' => (int) $user->isActive(),
            'failed_login_attempts' => $user->failedLoginAttempts(),
            'locked_until' => $user->lockedUntil()?->format('Y-m-d H:i:s'),
            'session_version' => $user->sessionVersion(),
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
            sessionVersion: (int) $row['session_version'],
        );
    }
}
