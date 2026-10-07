<?php

declare(strict_types=1);

namespace App\Account\Repository;

use App\Account\Repository\NotificationPreferenceRepositoryInterface;

final class MysqlNotificationPreferenceRepository implements NotificationPreferenceRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function emailEnabled(int $userId): bool
    {
        $statement = $this->pdo->prepare('SELECT email_notifications FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);
        $value = $statement->fetchColumn();

        return $value !== false && (bool) $value;
    }

    public function setEmailEnabled(int $userId, bool $enabled): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET email_notifications = :enabled WHERE id = :id');
        $statement->execute(['enabled' => (int) $enabled, 'id' => $userId]);
    }
}
