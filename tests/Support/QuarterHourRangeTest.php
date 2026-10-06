<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\QuarterHour;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #290 : la liste des quarts d'heure proposés dans les sélecteurs d'horaire. */
final class QuarterHourRangeTest extends TestCase
{
    #[Test]
    public function testItListsEveryQuarterHourFromTheFirstToTheLastIncluded(): void
    {
        self::assertSame(['08:00', '08:15', '08:30', '08:45', '09:00'], QuarterHour::range('08:00', '09:00'));
    }

    #[Test]
    public function testAFullDayHasNinetySixQuarterHours(): void
    {
        $day = QuarterHour::range('00:00', '23:45');

        self::assertCount(96, $day);
        self::assertSame('23:45', end($day));
    }

    #[Test]
    public function testEveryListedTimeIsAligned(): void
    {
        foreach (QuarterHour::range('00:00', '23:45') as $time) {
            self::assertTrue(QuarterHour::isAligned($time), $time);
        }
    }

    #[Test]
    public function testALastTimeBeforeTheFirstGivesAnEmptyList(): void
    {
        self::assertSame([], QuarterHour::range('10:00', '09:00'));
    }
}
