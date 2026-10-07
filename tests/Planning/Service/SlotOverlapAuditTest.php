<?php

declare(strict_types=1);

namespace App\Tests\Planning\Service;

use App\Planning\Entity\Weekday;
use App\Planning\Entity\RecurringSlot;
use App\Planning\Service\SlotOverlapAudit;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #263 : état des lieux des créneaux fixes qui se chevauchent déjà (avant l'exclusivité du local). */
final class SlotOverlapAuditTest extends TestCase
{
    private function slot(int $id, int $group, Weekday $day, string $start, string $end, bool $active = true): RecurringSlot
    {
        return new RecurringSlot($id, $group, $day, $start, $end, $active);
    }

    #[Test]
    public function testItListsEachOverlappingPairOnceAcrossGroups(): void
    {
        $pairs = (new SlotOverlapAudit())->pairs([
            $this->slot(1, 10, Weekday::Wednesday, '18:30:00', '22:45:00'),
            $this->slot(2, 20, Weekday::Wednesday, '18:00:00', '19:00:00'),
            $this->slot(3, 30, Weekday::Wednesday, '22:00:00', '23:00:00'),
        ]);

        self::assertSame([[1, 2], [1, 3]], array_map(static fn (array $pair): array => [$pair[0]->id(), $pair[1]->id()], $pairs));
    }

    #[Test]
    public function testContiguousOtherDayAndInactiveSlotsAreNotOverlaps(): void
    {
        $pairs = (new SlotOverlapAudit())->pairs([
            $this->slot(1, 10, Weekday::Wednesday, '14:00:00', '18:30:00'),
            $this->slot(2, 20, Weekday::Wednesday, '18:30:00', '22:45:00'),
            $this->slot(3, 30, Weekday::Thursday, '14:00:00', '22:45:00'),
            $this->slot(4, 40, Weekday::Wednesday, '15:00:00', '16:00:00', false),
        ]);

        self::assertSame([], $pairs);
    }

    #[Test]
    public function testNoSlotNoPair(): void
    {
        self::assertSame([], (new SlotOverlapAudit())->pairs([]));
    }
}
