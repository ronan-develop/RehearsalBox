<?php

declare(strict_types=1);

namespace App\Tests\Metrics\Alert;

use App\Metrics\Alert\AlertType;
use App\Metrics\Alert\MysqlAlertRepository;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class MysqlAlertRepositoryTest extends RepositoryTestCase
{
    #[Test]
    public function testTheLastSendIsRememberedPerTypeAndOverwritten(): void
    {
        $repository = new MysqlAlertRepository($this->pdo);
        self::assertNull($repository->lastSentAt(AlertType::DiskLow));

        $repository->markSent(AlertType::DiskLow, new \DateTimeImmutable('2026-10-07 10:00:00', new \DateTimeZone('UTC')));
        $repository->markSent(AlertType::DiskLow, new \DateTimeImmutable('2026-10-07 23:00:00', new \DateTimeZone('UTC')));
        $repository->markSent(AlertType::BackupOld, new \DateTimeImmutable('2026-10-07 11:00:00', new \DateTimeZone('UTC')));

        self::assertSame('2026-10-07 23:00:00', $repository->lastSentAt(AlertType::DiskLow)?->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-07 11:00:00', $repository->lastSentAt(AlertType::BackupOld)?->format('Y-m-d H:i:s'));
        self::assertNull($repository->lastSentAt(AlertType::Attack));
    }

    #[Test]
    public function testADateGivenInAnotherTimezoneIsStoredInUtc(): void
    {
        $repository = new MysqlAlertRepository($this->pdo);
        $repository->markSent(AlertType::CronSilent, new \DateTimeImmutable('2026-10-07 14:00:00', new \DateTimeZone('Europe/Paris')));

        self::assertSame('2026-10-07 12:00:00', $repository->lastSentAt(AlertType::CronSilent)?->format('Y-m-d H:i:s'));
    }
}
