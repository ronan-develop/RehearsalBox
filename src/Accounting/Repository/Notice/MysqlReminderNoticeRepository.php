<?php

declare(strict_types=1);

namespace App\Accounting\Repository\Notice;

final class MysqlReminderNoticeRepository implements ReminderNoticeRepositoryInterface
{
    private const FORMAT = 'Y-m-d H:i:s';
    private const EPOCH = '1970-01-02 00:00:00';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function claim(string $channel, \DateTimeImmutable $now, \DateTimeImmutable $notBefore): ?\DateTimeImmutable
    {
        $this->pdo->prepare('INSERT IGNORE INTO accounting_reminder_notices (channel, last_sent_at) VALUES (:channel, :epoch)')
            ->execute(['channel' => $channel, 'epoch' => self::EPOCH]);
        $read = $this->pdo->prepare('SELECT last_sent_at FROM accounting_reminder_notices WHERE channel = :channel');
        $read->execute(['channel' => $channel]);
        $previous = (string) $read->fetchColumn();

        $claim = $this->pdo->prepare('UPDATE accounting_reminder_notices SET last_sent_at = :now WHERE channel = :channel AND last_sent_at <= :not_before');
        $claim->execute(['now' => $now->format(self::FORMAT), 'channel' => $channel, 'not_before' => $notBefore->format(self::FORMAT)]);

        return $claim->rowCount() === 1 ? new \DateTimeImmutable($previous) : null;
    }

    public function release(string $channel, \DateTimeImmutable $claimedAt, \DateTimeImmutable $previous): void
    {
        $this->pdo->prepare('UPDATE accounting_reminder_notices SET last_sent_at = :previous WHERE channel = :channel AND last_sent_at = :claimed')
            ->execute(['previous' => $previous->format(self::FORMAT), 'channel' => $channel, 'claimed' => $claimedAt->format(self::FORMAT)]);
    }
}
