<?php

declare(strict_types=1);

namespace App\Tests\Metrics\Report;

use App\Metrics\Report\HumanDuration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HumanDurationTest extends TestCase
{
    #[Test]
    public function testUneDureeNegativeOuNulleSAfficheZeroMinute(): void
    {
        self::assertSame('0 min', HumanDuration::ofMinutes(-5));
        self::assertSame('0 min', HumanDuration::ofMinutes(0));
    }

    #[Test]
    public function testMoinsDUneHeureSAfficheEnMinutes(): void
    {
        self::assertSame('59 min', HumanDuration::ofMinutes(59));
    }

    #[Test]
    public function testDeLUneHeureAuxQuaranteHuitHeuresSAfficheEnHeures(): void
    {
        self::assertSame('1 h', HumanDuration::ofMinutes(60));
        self::assertSame('47 h', HumanDuration::ofMinutes(2879));
    }

    #[Test]
    public function testAPartirDeQuaranteHuitHeuresSAfficheEnJours(): void
    {
        self::assertSame('2 j', HumanDuration::ofMinutes(2880));
        self::assertSame('3 j', HumanDuration::ofMinutes(4320));
    }
}
