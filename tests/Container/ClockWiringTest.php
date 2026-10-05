<?php

declare(strict_types=1);

namespace App\Tests\Container;

use App\Container\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Le temps est un port (ClockInterface, compatible PSR-20) : le métier dépend de l'interface,
 * l'adaptateur réel est lié dans config/services.php, les tests le remplacent par MockClock.
 */
final class ClockWiringTest extends TestCase
{
    private function container(): Container
    {
        $config = require __DIR__ . '/../../config/config.php';

        return (require __DIR__ . '/../../config/services.php')($config);
    }

    #[Test]
    public function testTheContainerProvidesTheSystemClockBehindTheInterface(): void
    {
        $clock = $this->container()->get(ClockInterface::class);

        self::assertInstanceOf(ClockInterface::class, $clock);
        self::assertInstanceOf(Clock::class, $clock);
        self::assertEqualsWithDelta(time(), $clock->now()->getTimestamp(), 2);
    }

    #[Test]
    public function testTheSameClockInstanceIsSharedAcrossServices(): void
    {
        $container = $this->container();

        self::assertSame($container->get(ClockInterface::class), $container->get(ClockInterface::class));
    }

    #[Test]
    public function testTestsCanSwapInAFrozenClock(): void
    {
        $container = $this->container();
        $container->set(ClockInterface::class, static fn () => new MockClock('2026-10-05 09:00:00'));

        self::assertSame('2026-10-05 09:00:00', $container->get(ClockInterface::class)->now()->format('Y-m-d H:i:s'));
    }
}
