<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Enum\SlotExceptionStatus;
use App\Entity\Enum\Weekday;
use App\Entity\RecurringSlot;
use App\Entity\SlotException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #263 : une demande vise tout le créneau du titulaire ou seulement une plage comprise dedans. */
final class SlotExceptionRangeTest extends TestCase
{
    private function exception(?string $start, ?string $end): SlotException
    {
        return new SlotException(1, 5, new \DateTimeImmutable('2026-10-14'), SlotExceptionStatus::EnAttente, 2, 9, null, null, new \DateTimeImmutable('2026-10-06'), $start, $end);
    }

    #[Test]
    public function testWithoutARangeTheRequestCoversTheWholeHolderSlot(): void
    {
        $holder = new RecurringSlot(5, 1, Weekday::Wednesday, '18:30:00', '22:45:00', true);

        $applied = $this->exception(null, null)->appliedTo($holder);

        self::assertSame($holder, $applied);
        self::assertNull($this->exception(null, null)->startTime());
    }

    #[Test]
    public function testWithARangeTheAppliedSlotKeepsTheHoldersDayAndIdButTakesTheRequestedHours(): void
    {
        $holder = new RecurringSlot(5, 1, Weekday::Wednesday, '18:30:00', '22:45:00', true);

        $applied = $this->exception('18:30:00', '19:00:00')->appliedTo($holder);

        self::assertSame(Weekday::Wednesday, $applied->weekday());
        self::assertSame('18:30:00', $applied->startTime());
        self::assertSame('19:00:00', $applied->endTime());
        self::assertSame($holder->groupId(), $applied->groupId());
        self::assertSame($holder->id(), $applied->id());
    }
}
