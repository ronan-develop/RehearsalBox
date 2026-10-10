<?php

declare(strict_types=1);

namespace App\Tests\Account\Service\Throttle;

use App\Account\Repository\MysqlThrottleEventRepository;
use App\Account\Service\Throttle\SubjectThrottle;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class SubjectThrottleTest extends RepositoryTestCase
{
    private const LIMIT = 4;

    private MysqlThrottleEventRepository $events;
    private SubjectThrottle $throttle;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new MysqlThrottleEventRepository($this->pdo);
        $this->throttle = new SubjectThrottle($this->events, 'login', self::LIMIT, '-15 minutes');
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
        $reset = new SubjectThrottle($this->events, 'password-reset', self::LIMIT, '-15 minutes');
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
        self::assertSame(3600, (new SubjectThrottle($this->events, 'x', 1, '-1 hour'))->retryAfterSeconds());
    }

    // --- Durée restante du blocage (#236) ------------------------------------------------------------

    #[Test]
    public function testRemainingSecondsIsZeroWhileTheSubjectIsNotBlocked(): void
    {
        for ($i = 1; $i < self::LIMIT; ++$i) {
            $this->throttle->record('203.0.113.7', $this->now);
        }

        self::assertSame(0, $this->throttle->remainingSeconds('203.0.113.7', $this->now));
        self::assertSame(0, $this->throttle->remainingSeconds('198.51.100.9', $this->now));
        self::assertSame(0, $this->throttle->remainingSeconds('', $this->now));
    }

    #[Test]
    public function testRemainingSecondsIsTheWholeWindowRightAfterTheBlockingEventThenShrinks(): void
    {
        for ($i = 0; $i < self::LIMIT; ++$i) {
            $this->throttle->record('203.0.113.7', $this->now);
        }

        self::assertSame(900, $this->throttle->remainingSeconds('203.0.113.7', $this->now));
        self::assertSame(600, $this->throttle->remainingSeconds('203.0.113.7', $this->now->modify('+5 minutes')));
        self::assertSame(1, $this->throttle->remainingSeconds('203.0.113.7', $this->now->modify('+899 seconds')));
        self::assertSame(1, $this->throttle->remainingSeconds('203.0.113.7', $this->now->modify('+900 seconds')), 'pile à la limite : encore bloqué (comme isBlocked), jamais « 0 minute »');
        self::assertSame(0, $this->throttle->remainingSeconds('203.0.113.7', $this->now->modify('+901 seconds')), 'le blocage est levé');
    }

    #[Test]
    public function testTheBlockLiftsWhenTheEventThatCrossedTheLimitLeavesTheWindowNotTheLatestOne(): void
    {
        // Limite de 4 : évènements à +0, +2, +4 et +6 minutes. Les quatre comptent jusqu'à ce que le premier sorte (à +15).
        foreach (['+0 minutes', '+2 minutes', '+4 minutes', '+6 minutes'] as $offset) {
            $this->throttle->record('203.0.113.7', $this->now->modify($offset));
        }
        $at = $this->now->modify('+6 minutes');

        self::assertSame(540, $this->throttle->remainingSeconds('203.0.113.7', $at), '15 min − 6 min = 9 min jusqu\'à la sortie du premier');
        self::assertFalse($this->throttle->isBlocked('203.0.113.7', $this->now->modify('+16 minutes')));
    }

    #[Test]
    public function testRemainingSecondsIsRoundedUpToTheSecond(): void
    {
        for ($i = 0; $i < self::LIMIT; ++$i) {
            $this->throttle->record('203.0.113.7', $this->now);
        }

        self::assertSame(900, $this->throttle->remainingSeconds('203.0.113.7', $this->now->modify('+100 milliseconds')));
    }

    #[Test]
    public function testForgetClearsOneSubjectWithoutTouchingTheOthers(): void
    {
        foreach (['203.0.113.7', '198.51.100.9'] as $subject) {
            for ($i = 0; $i < self::LIMIT; ++$i) {
                $this->throttle->record($subject, $this->now);
            }
        }

        $this->throttle->forget('203.0.113.7');

        self::assertFalse($this->throttle->isBlocked('203.0.113.7', $this->now));
        self::assertTrue($this->throttle->isBlocked('198.51.100.9', $this->now));
        self::assertSame(0, $this->throttle->remainingSeconds('203.0.113.7', $this->now));
    }
}
