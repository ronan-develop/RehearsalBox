<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\MysqlThrottleEventRepository;
use App\Service\IpThrottle;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class IpThrottleTest extends RepositoryTestCase
{
    private const LIMIT = 4;

    private MysqlThrottleEventRepository $events;
    private IpThrottle $throttle;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new MysqlThrottleEventRepository($this->pdo);
        $this->throttle = new IpThrottle($this->events, 'login', self::LIMIT, '-15 minutes');
        $this->now = new \DateTimeImmutable('2026-01-01 10:00:00');
    }

    #[Test]
    public function testAnAddressIsBlockedOnceItReachesTheLimit(): void
    {
        for ($i = 1; $i < self::LIMIT; ++$i) {
            $this->throttle->record('203.0.113.7', $this->now);
        }
        self::assertFalse($this->throttle->isBlocked('203.0.113.7', $this->now), 'une de moins que la limite');

        $this->throttle->record('203.0.113.7', $this->now);

        self::assertTrue($this->throttle->isBlocked('203.0.113.7', $this->now));
    }

    #[Test]
    public function testAnotherAddressIsNotAffected(): void
    {
        for ($i = 0; $i < self::LIMIT; ++$i) {
            $this->throttle->record('203.0.113.7', $this->now);
        }

        self::assertFalse($this->throttle->isBlocked('198.51.100.9', $this->now));
    }

    #[Test]
    public function testTheBlockEndsOnceTheEventsLeaveTheWindow(): void
    {
        for ($i = 0; $i < self::LIMIT; ++$i) {
            $this->throttle->record('203.0.113.7', $this->now);
        }

        self::assertTrue($this->throttle->isBlocked('203.0.113.7', $this->now->modify('+14 minutes')));
        self::assertFalse($this->throttle->isBlocked('203.0.113.7', $this->now->modify('+16 minutes')));
    }

    #[Test]
    public function testTwoThrottlesWithDifferentLabelsNeverShareTheirCounts(): void
    {
        $reset = new IpThrottle($this->events, 'password-reset', self::LIMIT, '-15 minutes');
        for ($i = 0; $i < self::LIMIT; ++$i) {
            $this->throttle->record('203.0.113.7', $this->now);
        }

        self::assertTrue($this->throttle->isBlocked('203.0.113.7', $this->now));
        self::assertFalse($reset->isBlocked('203.0.113.7', $this->now), 'la limite de connexion ne bloque pas le mot de passe oublié');
    }

    #[Test]
    public function testNoAddressIsStoredInClearAndOldRowsArePurged(): void
    {
        $this->throttle->record('203.0.113.7', $this->now->modify('-2 days'));
        $this->throttle->record('203.0.113.7', $this->now);

        $rows = $this->pdo->query('SELECT subject_hash FROM throttle_events')->fetchAll(\PDO::FETCH_COLUMN);

        self::assertCount(1, $rows, 'les lignes de plus de 24 h sont purgées');
        self::assertSame(64, strlen($rows[0]));
        self::assertStringNotContainsString('203.0.113.7', $rows[0]);
        self::assertNotSame(hash('sha256', '203.0.113.7'), $rows[0], "l'empreinte est propre à l'application, pas un simple SHA-256 de l'adresse");
    }

    #[Test]
    public function testAnUnknownAddressIsNeverBlockedAndNeverRecorded(): void
    {
        for ($i = 0; $i < self::LIMIT + 5; ++$i) {
            $this->throttle->record('', $this->now);
        }

        self::assertFalse($this->throttle->isBlocked('', $this->now));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM throttle_events')->fetchColumn());
    }

    #[Test]
    public function testRetryAfterIsTheWholeWindowInSeconds(): void
    {
        self::assertSame(900, $this->throttle->retryAfterSeconds());
        self::assertSame(3600, (new IpThrottle($this->events, 'x', 1, '-1 hour'))->retryAfterSeconds());
    }
}
