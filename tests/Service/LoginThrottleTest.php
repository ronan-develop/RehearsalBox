<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\MysqlLoginFailureRepository;
use App\Service\LoginThrottle;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

final class LoginThrottleTest extends RepositoryTestCase
{
    private LoginThrottle $throttle;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->throttle = new LoginThrottle(new MysqlLoginFailureRepository($this->pdo));
        $this->now = new \DateTimeImmutable('2026-01-01 10:00:00');
    }

    #[Test]
    public function testAnAddressIsBlockedOnceItReachesTheLimitOfFailures(): void
    {
        for ($i = 1; $i < LoginThrottle::MAX_FAILURES; ++$i) {
            $this->throttle->recordFailure('203.0.113.7', $this->now);
        }
        self::assertFalse($this->throttle->isBlocked('203.0.113.7', $this->now), 'une de moins que la limite');

        $this->throttle->recordFailure('203.0.113.7', $this->now);

        self::assertTrue($this->throttle->isBlocked('203.0.113.7', $this->now));
    }

    #[Test]
    public function testAnotherAddressIsNotAffected(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; ++$i) {
            $this->throttle->recordFailure('203.0.113.7', $this->now);
        }

        self::assertFalse($this->throttle->isBlocked('198.51.100.9', $this->now));
    }

    #[Test]
    public function testTheBlockEndsOnceTheFailuresLeaveTheWindow(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; ++$i) {
            $this->throttle->recordFailure('203.0.113.7', $this->now);
        }

        self::assertTrue($this->throttle->isBlocked('203.0.113.7', $this->now->modify('+14 minutes')));
        self::assertFalse($this->throttle->isBlocked('203.0.113.7', $this->now->modify('+16 minutes')));
    }

    #[Test]
    public function testNoAddressIsStoredInClearAndOldRowsArePurged(): void
    {
        $this->throttle->recordFailure('203.0.113.7', $this->now->modify('-2 days'));
        $this->throttle->recordFailure('203.0.113.7', $this->now);

        $rows = $this->pdo->query('SELECT ip_hash FROM login_failures')->fetchAll(\PDO::FETCH_COLUMN);

        self::assertCount(1, $rows, 'les lignes de plus de 24 h sont purgées');
        self::assertSame(64, strlen($rows[0]));
        self::assertStringNotContainsString('203.0.113.7', $rows[0]);
        self::assertNotSame(hash('sha256', '203.0.113.7'), $rows[0], "l'empreinte est propre à l'application, pas un simple SHA-256 de l'adresse");
    }

    #[Test]
    public function testAnUnknownAddressIsNeverBlockedAndNeverRecorded(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES + 5; ++$i) {
            $this->throttle->recordFailure('', $this->now);
        }

        self::assertFalse($this->throttle->isBlocked('', $this->now));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM login_failures')->fetchColumn());
    }
}
