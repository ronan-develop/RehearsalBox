<?php

declare(strict_types=1);

namespace App\Tests\Planning\Service;

use App\Planning\Entity\RecurringSlot;
use App\Planning\Entity\Weekday;
use App\Planning\Service\SlotRequestRange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** La plage d'une demande d'échange : tout le créneau, ou une plage au quart d'heure comprise dedans (#263). */
final class SlotRequestRangeTest extends TestCase
{
    private function slot(): RecurringSlot
    {
        return new RecurringSlot(1, 5, Weekday::Wednesday, '18:00:00', '22:00:00', true);
    }

    #[Test]
    public function testNoBoundMeansTheWholeSlotAndNoError(): void
    {
        self::assertSame([], SlotRequestRange::errors($this->slot(), null, null));
    }

    #[Test]
    public function testAPartInsideTheSlotIsAccepted(): void
    {
        self::assertSame([], SlotRequestRange::errors($this->slot(), '19:00', '20:30'));
        self::assertSame([], SlotRequestRange::errors($this->slot(), '18:00:00', '22:00:00'));
    }

    #[Test]
    public function testOneBoundAloneIsRefused(): void
    {
        self::assertArrayHasKey('startTime', SlotRequestRange::errors($this->slot(), '19:00', null));
        self::assertArrayHasKey('startTime', SlotRequestRange::errors($this->slot(), null, '20:00'));
    }

    #[Test]
    public function testATimeOffTheQuarterHourNamesTheFaultyField(): void
    {
        self::assertSame(['startTime'], array_keys(SlotRequestRange::errors($this->slot(), '19:10', '20:00')));
        self::assertSame(['endTime'], array_keys(SlotRequestRange::errors($this->slot(), '19:00', '20:20')));
    }

    #[Test]
    public function testTheEndMustFollowTheStartAndStayInsideTheSlot(): void
    {
        self::assertArrayHasKey('endTime', SlotRequestRange::errors($this->slot(), '20:00', '19:00'));
        self::assertArrayHasKey('endTime', SlotRequestRange::errors($this->slot(), '20:00', '20:00'));
        self::assertArrayHasKey('startTime', SlotRequestRange::errors($this->slot(), '17:45', '19:00'));
        self::assertArrayHasKey('startTime', SlotRequestRange::errors($this->slot(), '21:00', '22:15'));
    }
}
