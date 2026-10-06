<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\TimeRange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #263 : une plage horaire d'une même journée, début strictement avant la fin (valeur immuable). */
final class TimeRangeTest extends TestCase
{
    #[Test]
    public function testItKeepsItsBoundsAndRefusesAnEmptyOrBackwardsRange(): void
    {
        $range = new TimeRange('18:30:00', '19:00:00');

        self::assertSame('18:30:00', $range->start());
        self::assertSame('19:00:00', $range->end());
        foreach ([['19:00:00', '18:30:00'], ['19:00:00', '19:00:00']] as [$start, $end]) {
            try {
                new TimeRange($start, $end);
                self::fail('plage vide ou à l\'envers');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testFromColumnsGivesNoRangeWhenBothBoundsAreNull(): void
    {
        self::assertNull(TimeRange::fromColumns(null, null));
        self::assertEquals(new TimeRange('18:00:00', '18:30:00'), TimeRange::fromColumns('18:00:00', '18:30:00'));
    }

    #[Test]
    public function testItIsContainedInAnotherRangeOnlyWhenBothBoundsFit(): void
    {
        $slot = new TimeRange('18:30:00', '22:45:00');

        self::assertTrue((new TimeRange('18:30:00', '19:00:00'))->isWithin($slot));
        self::assertTrue((new TimeRange('18:30:00', '22:45:00'))->isWithin($slot), 'toute la plage : permis');
        self::assertFalse((new TimeRange('18:00:00', '19:00:00'))->isWithin($slot));
        self::assertFalse((new TimeRange('22:00:00', '23:00:00'))->isWithin($slot));
    }
}
