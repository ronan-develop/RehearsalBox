<?php

declare(strict_types=1);

namespace App\Tests\Planning\Entity;

use App\Planning\Entity\SlotExceptionStatus;
use App\Planning\Entity\Weekday;
use App\Planning\Entity\RecurringSlot;
use App\Planning\Entity\Requester;
use App\Planning\Entity\SlotException;
use App\Planning\Entity\TimeRange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #263 : une demande vise tout le créneau du titulaire ou seulement une plage comprise dedans. */
final class SlotExceptionRangeTest extends TestCase
{
    private function exception(?TimeRange $range): SlotException
    {
        return new SlotException(1, 5, new \DateTimeImmutable('2026-10-14'), SlotExceptionStatus::EnAttente, new Requester(2, 9), null, null, new \DateTimeImmutable('2026-10-06'), $range);
    }

    #[Test]
    public function testWithoutARangeTheRequestCoversTheWholeHolderSlot(): void
    {
        $holder = new RecurringSlot(5, 1, Weekday::Wednesday, '18:30:00', '22:45:00', true);

        self::assertNull($this->exception(null)->range());
        self::assertSame($holder, $holder->within($this->exception(null)->range()));
    }

    #[Test]
    public function testWithARangeTheAppliedSlotKeepsTheHoldersDayAndIdButTakesTheRequestedHours(): void
    {
        $holder = new RecurringSlot(5, 1, Weekday::Wednesday, '18:30:00', '22:45:00', true);

        $range = $this->exception(new TimeRange('18:30:00', '19:00:00'))->range();
        $applied = $holder->within($range);

        self::assertSame(Weekday::Wednesday, $applied->weekday());
        self::assertSame('18:30:00', $applied->startTime());
        self::assertSame('19:00:00', $applied->endTime());
        self::assertSame($holder->groupId(), $applied->groupId());
        self::assertSame($holder->id(), $applied->id());
    }

    #[Test]
    public function testTheRequesterGroupsTheGroupAndThePerson(): void
    {
        $exception = $this->exception(null);

        self::assertSame(2, $exception->requester()->groupId());
        self::assertSame(9, $exception->requester()->userId());
    }
}
