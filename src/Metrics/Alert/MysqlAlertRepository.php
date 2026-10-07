<?php

declare(strict_types=1);

namespace App\Metrics\Alert;

final class MysqlAlertRepository implements AlertRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function lastSentAt(AlertType $type): ?\DateTimeImmutable
    {
        $statement = $this->pdo->prepare('SELECT last_sent_at FROM metric_alerts WHERE alert_key = :key');
        $statement->execute(['key' => $type->value]);
        $value = $statement->fetchColumn();

        return $value === false ? null : new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
    }

    public function markSent(AlertType $type, \DateTimeImmutable $at): void
    {
        $this->pdo->prepare(
            'INSERT INTO metric_alerts (alert_key, last_sent_at) VALUES (:key, :at) ON DUPLICATE KEY UPDATE last_sent_at = VALUES(last_sent_at)'
        )->execute(['key' => $type->value, 'at' => $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
    }
}
