<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\SessionPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SessionPolicyTest extends TestCase
{
    private const NOW = 2_000_000_000;

    #[Test]
    public function testARecentlyUsedSessionIsValid(): void
    {
        self::assertFalse(SessionPolicy::isExpired(self::NOW - 3600, self::NOW - 60, self::NOW));
    }

    #[Test]
    public function testASessionIdleForLongerThanTheIdleLimitIsExpired(): void
    {
        self::assertTrue(SessionPolicy::isExpired(self::NOW - 3600, self::NOW - SessionPolicy::IDLE_SECONDS - 1, self::NOW));
        self::assertFalse(SessionPolicy::isExpired(self::NOW - 3600, self::NOW - SessionPolicy::IDLE_SECONDS, self::NOW));
    }

    #[Test]
    public function testASessionOlderThanTheAbsoluteLimitIsExpiredEvenIfActive(): void
    {
        self::assertTrue(SessionPolicy::isExpired(self::NOW - SessionPolicy::ABSOLUTE_SECONDS - 1, self::NOW - 5, self::NOW));
        self::assertFalse(SessionPolicy::isExpired(self::NOW - SessionPolicy::ABSOLUTE_SECONDS, self::NOW - 5, self::NOW));
    }

    #[Test]
    public function testTheIdleLimitNeverExceedsTheAbsoluteLimit(): void
    {
        self::assertLessThanOrEqual(SessionPolicy::ABSOLUTE_SECONDS, SessionPolicy::IDLE_SECONDS);
    }
}
