<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Notification;

use App\Accounting\Repository\Notice\MysqlReminderNoticeRepository;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

/** #362 : réservation atomique d'un rappel par canal : un seul par intervalle, deux connexions réelles, échec d'envoi libère. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class ReminderNoticeRepositoryTest extends RepositoryTestCase
{
    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time);
    }

    #[Test]
    public function testTheFirstClaimSucceedsAndASecondOneWithinTheIntervalIsRefused(): void
    {
        $repository = new MysqlReminderNoticeRepository($this->pdo);

        self::assertNotNull($repository->claim('treasurer', $this->at('2026-10-20 10:00:00'), $this->at('2026-10-13 10:00:00')));
        self::assertNull($repository->claim('treasurer', $this->at('2026-10-20 11:00:00'), $this->at('2026-10-13 11:00:00')), 'moins de 7 jours');
        self::assertNotNull($repository->claim('president', $this->at('2026-10-20 11:00:00'), $this->at('2026-10-13 11:00:00')), 'un autre canal est indépendant');
    }

    #[Test]
    public function testAClaimIsPossibleAgainOnceTheIntervalHasPassed(): void
    {
        $repository = new MysqlReminderNoticeRepository($this->pdo);
        $repository->claim('treasurer', $this->at('2026-10-20 10:00:00'), $this->at('2026-10-13 10:00:00'));

        self::assertNotNull($repository->claim('treasurer', $this->at('2026-10-27 10:00:00'), $this->at('2026-10-20 10:00:00')));
    }

    #[Test]
    public function testReleasingAFailedSendRestoresThePreviousDate(): void
    {
        $repository = new MysqlReminderNoticeRepository($this->pdo);
        $first = $this->at('2026-10-20 10:00:00');
        $repository->claim('treasurer', $first, $this->at('2026-10-13 10:00:00'));
        $second = $this->at('2026-10-27 10:00:00');
        $previous = $repository->claim('treasurer', $second, $this->at('2026-10-20 10:00:00'));
        self::assertEquals($first, $previous);

        $repository->release('treasurer', $second, $previous);

        self::assertSame('2026-10-20 10:00:00', (string) $this->pdo->query("SELECT last_sent_at FROM accounting_reminder_notices WHERE channel = 'treasurer'")->fetchColumn());
        self::assertNotNull($repository->claim('treasurer', $this->at('2026-10-27 12:00:00'), $this->at('2026-10-20 12:00:00')), 'réessayable');
    }

    #[Test]
    public function testAFirstEverFailureLeavesTheChannelClaimable(): void
    {
        $repository = new MysqlReminderNoticeRepository($this->pdo);
        $now = $this->at('2026-10-20 10:00:00');
        $previous = $repository->claim('treasurer', $now, $this->at('2026-10-13 10:00:00'));

        $repository->release('treasurer', $now, $previous ?? $now);

        self::assertNotNull($repository->claim('treasurer', $this->at('2026-10-20 10:30:00'), $this->at('2026-10-13 10:30:00')));
    }

    #[Test]
    public function testAReleaseDoesNotUndoAnotherRunsClaim(): void
    {
        $repository = new MysqlReminderNoticeRepository($this->pdo);
        $mine = $this->at('2026-10-20 10:00:00');
        $previous = $repository->claim('treasurer', $mine, $this->at('2026-10-13 10:00:00')) ?? $mine;
        $this->pdo->exec("UPDATE accounting_reminder_notices SET last_sent_at = '2026-10-20 10:05:00' WHERE channel = 'treasurer'");

        $repository->release('treasurer', $mine, $previous);

        self::assertSame('2026-10-20 10:05:00', (string) $this->pdo->query("SELECT last_sent_at FROM accounting_reminder_notices WHERE channel = 'treasurer'")->fetchColumn());
    }

    #[Test]
    public function testTwoRealConnectionsClaimTheSameSlotOnlyOnce(): void
    {
        $other = \App\Tests\Database\TestDatabase::connection();
        $first = new MysqlReminderNoticeRepository($this->pdo);
        $second = new MysqlReminderNoticeRepository($other);
        $now = $this->at('2026-10-20 10:00:00');
        $limit = $this->at('2026-10-13 10:00:00');

        $results = [$first->claim('treasurer', $now, $limit), $second->claim('treasurer', $now->modify('+1 second'), $limit)];

        self::assertCount(1, array_filter($results, static fn ($r): bool => $r !== null));
    }
}
