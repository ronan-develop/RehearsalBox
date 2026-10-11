<?php

declare(strict_types=1);

namespace App\Tests\Account\Service\Throttle;

use App\Account\Repository\MysqlThrottleEventRepository;
use App\Account\Service\Throttle\SubjectThrottle;
use App\Account\Service\Throttle\UserActionLimit;
use App\Security\Exception\RateLimitedException;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

/** Limite d'une action par PERSONNE connectée (#428) : N actions dans la fenêtre, puis refus avec le délai restant. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class UserActionLimitTest extends RepositoryTestCase
{
    private MockClock $clock;
    private MysqlThrottleEventRepository $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-11 10:00:00');
        $this->events = new MysqlThrottleEventRepository($this->pdo);
    }

    private function limit(string $label = 'search', int $max = 3, string $window = '-1 minute'): UserActionLimit
    {
        return new UserActionLimit(new SubjectThrottle($this->events, $label, $max, $window), $this->clock, 'Trop de recherches.');
    }

    #[Test]
    public function testActionsWithinTheLimitPass(): void
    {
        $limit = $this->limit();
        for ($i = 0; $i < 3; ++$i) {
            $limit->hit(7);
        }

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function testTheActionAfterTheLimitIsRefusedWithTheTimeToWait(): void
    {
        $limit = $this->limit();
        for ($i = 0; $i < 3; ++$i) {
            $limit->hit(7);
        }

        try {
            $limit->hit(7);
            self::fail('la quatrième action de la minute doit être refusée');
        } catch (RateLimitedException $e) {
            self::assertSame('Trop de recherches.', $e->getMessage());
            self::assertGreaterThan(0, $e->retryAfterSeconds());
            self::assertLessThanOrEqual(60, $e->retryAfterSeconds());
        }
    }

    #[Test]
    public function testARefusedActionDoesNotExtendTheBlock(): void
    {
        $limit = $this->limit();
        for ($i = 0; $i < 3; ++$i) {
            $limit->hit(7);
        }
        foreach (range(1, 5) as $ignored) {
            try {
                $limit->hit(7);
            } catch (RateLimitedException) {
            }
        }

        $this->clock->modify('+61 seconds');
        $limit->hit(7);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function testAnotherPersonAndAnotherActionAreNotAffected(): void
    {
        $search = $this->limit('search');
        for ($i = 0; $i < 3; ++$i) {
            $search->hit(7);
        }

        $search->hit(8);
        $this->limit('upload')->hit(7);

        $this->expectNotToPerformAssertions();
    }
}
