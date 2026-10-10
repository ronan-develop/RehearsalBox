<?php

declare(strict_types=1);

namespace App\Account\Repository;

use App\Account\Entity\UserRole;
use App\Account\Entity\User;
use App\Account\Repository\UserRepositoryInterface;

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

    public function lockActiveAdminIds(): array
    {
        $statement = $this->pdo->prepare('SELECT id FROM users WHERE role = :role AND is_active = 1 ORDER BY id FOR UPDATE');
        $statement->execute(['role' => UserRole::Admin->value]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function updateRole(int $userId, UserRole $role): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
        $statement->execute(['role' => $role->value, 'id' => $userId]);
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

    /** Plafond du compteur : la colonne est un petit entier, un compte attaqué pendant des jours ne doit pas la faire déborder. */
    private const MAX_RECORDED_FAILURES = 100;

    public function recordFailedLogin(int $userId, int $maxAttempts, \DateTimeImmutable $now, string $lockDuration): void
    {
        // « locked_until » AVANT le compteur : MariaDB évalue les affectations de gauche à droite, l'ordre fait lire l'ancienne valeur.
        $this->pdo->prepare(
            'UPDATE users SET
                 locked_until = IF(failed_login_attempts + 1 >= :max_attempts, :locked_until, locked_until),
                 failed_login_attempts = LEAST(failed_login_attempts + 1, :cap)
             WHERE id = :id'
        )->execute([
            'max_attempts' => $maxAttempts,
            'locked_until' => $now->modify($lockDuration)->format('Y-m-d H:i:s'),
            'cap' => self::MAX_RECORDED_FAILURES,
            'id' => $userId,
        ]);
    }

    public function resetFailedLogins(int $userId): void
    {
        $this->pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id')->execute(['id' => $userId]);
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
