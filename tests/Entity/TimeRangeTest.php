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

    #[Test]
    public function testTheIntersectionIsTheSharedPartAndTouchingBoundsShareNothing(): void
    {
        $wanted = new TimeRange('09:00:00', '19:00:00');

        self::assertEquals(new TimeRange('18:30:00', '19:00:00'), $wanted->intersect(new TimeRange('18:30:00', '22:45:00')), 'partiel');
        self::assertEquals(new TimeRange('10:00:00', '12:00:00'), $wanted->intersect(new TimeRange('10:00:00', '12:00:00')), 'contenu');
        self::assertEquals($wanted, $wanted->intersect(new TimeRange('08:00:00', '20:00:00')), 'recouvrant');
        self::assertNull($wanted->intersect(new TimeRange('19:00:00', '22:00:00')), 'contigu : rien en commun');
        self::assertNull($wanted->intersect(new TimeRange('20:00:00', '21:00:00')), 'disjoint');
    }

    #[Test]
    public function testSubtractingLeavesTheFreePartsInOrder(): void
    {
        $wanted = new TimeRange('09:00:00', '19:00:00');
        $parts = static fn (array $list): array => array_map(static fn (TimeRange $r): string => $r->start() . '-' . $r->end(), $list);

        self::assertSame(['09:00:00-19:00:00'], $parts($wanted->subtract([])), 'rien à retirer');
        self::assertSame(['09:00:00-18:30:00'], $parts($wanted->subtract([new TimeRange('18:30:00', '22:45:00')])), 'la fin est prise');
        self::assertSame(['12:00:00-19:00:00'], $parts($wanted->subtract([new TimeRange('08:00:00', '12:00:00')])), 'le début est pris');
        self::assertSame(['09:00:00-12:00:00', '14:00:00-19:00:00'], $parts($wanted->subtract([new TimeRange('12:00:00', '14:00:00')])), 'un trou au milieu coupe en deux');
        self::assertSame([], $parts($wanted->subtract([new TimeRange('08:00:00', '20:00:00')])), 'tout est pris');
        self::assertSame(['09:00:00-19:00:00'], $parts($wanted->subtract([new TimeRange('19:00:00', '22:00:00')])), 'contigu : rien à retirer');
    }

    #[Test]
    public function testSeveralOverlappingOrUnsortedRangesAreSubtractedCorrectly(): void
    {
        $wanted = new TimeRange('09:00:00', '22:00:00');
        $parts = static fn (array $list): array => array_map(static fn (TimeRange $r): string => $r->start() . '-' . $r->end(), $list);

        $free = $wanted->subtract([
            new TimeRange('18:00:00', '20:00:00'),
            new TimeRange('10:00:00', '12:00:00'),
            new TimeRange('11:00:00', '13:00:00'), // chevauche la précédente
            new TimeRange('13:00:00', '14:00:00'), // contiguë à la précédente
        ]);

        self::assertSame(['09:00:00-10:00:00', '14:00:00-18:00:00', '20:00:00-22:00:00'], $parts($free));
    }
}

