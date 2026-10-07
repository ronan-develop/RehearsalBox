<?php

declare(strict_types=1);

namespace App\Tests\Account\Repository;

use App\Account\Repository\MysqlThrottleEventRepository;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class MysqlThrottleEventRepositoryTest extends RepositoryTestCase
{
    private const NOW = '2026-01-01 10:00:00';

    #[Test]
    public function testItCountsTheFailuresOfOneAddressSinceAGivenDate(): void
    {
        $repository = new MysqlThrottleEventRepository($this->pdo);
        $repository->record(str_repeat('a', 64), new \DateTimeImmutable('2026-01-01 09:30:00'));
        $repository->record(str_repeat('a', 64), new \DateTimeImmutable('2026-01-01 09:50:00'));
        $repository->record(str_repeat('a', 64), new \DateTimeImmutable('2026-01-01 09:58:00'));
        $repository->record(str_repeat('b', 64), new \DateTimeImmutable('2026-01-01 09:59:00'));

        self::assertSame(2, $repository->countSince(str_repeat('a', 64), new \DateTimeImmutable('2026-01-01 09:45:00')));
        self::assertSame(3, $repository->countSince(str_repeat('a', 64), new \DateTimeImmutable('2026-01-01 09:00:00')));
        self::assertSame(1, $repository->countSince(str_repeat('b', 64), new \DateTimeImmutable('2026-01-01 09:00:00')));
        self::assertSame(0, $repository->countSince(str_repeat('c', 64), new \DateTimeImmutable('2026-01-01 09:00:00')));
    }

    #[Test]
    public function testItPurgesOnlyTheRowsOlderThanTheGivenDate(): void
    {
        $repository = new MysqlThrottleEventRepository($this->pdo);
        $repository->record(str_repeat('a', 64), new \DateTimeImmutable('2025-12-30 10:00:00'));
        $repository->record(str_repeat('a', 64), new \DateTimeImmutable('2026-01-01 09:00:00'));

        $repository->purgeBefore(new \DateTimeImmutable('2025-12-31 10:00:00'));

        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM throttle_events')->fetchColumn());
        self::assertSame(1, $repository->countSince(str_repeat('a', 64), new \DateTimeImmutable('2025-12-01 00:00:00')));
    }
}
