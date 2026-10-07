<?php

declare(strict_types=1);

namespace App\Tests\Planning\Presenter;

use App\Planning\Entity\Weekday;
use App\Planning\Entity\RecurringSlot;
use App\Planning\Entity\RequestableSlot;
use App\Planning\Presenter\PlanningDays;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlanningDaysTest extends TestCase
{
    private function slot(string $group, Weekday $weekday, string $start = '18:00:00'): RequestableSlot
    {
        return new RequestableSlot(new RecurringSlot(1, 1, $weekday, $start, '20:00:00', true), $group, 1);
    }

    /** @param list<\App\Planning\Presenter\PlanningDay> $days @return list<string> */
    private function summary(array $days): array
    {
        return array_map(
            static fn ($day): string => $day->weekday()->name . ($day->isToday() ? '*' : '') . ':' . implode(',', array_map(static fn (RequestableSlot $s): string => $s->groupName(), $day->slots())),
            $days,
        );
    }

    #[Test]
    public function testDaysStartTodayAndWrapAroundTheWeekOnlyWhereThereIsASlot(): void
    {
        $slots = [
            $this->slot('Lundi band', Weekday::Monday),
            $this->slot('Mercredi band', Weekday::Wednesday),
            $this->slot('Samedi band', Weekday::Saturday),
        ];

        $days = (new PlanningDays())->group($slots, new \DateTimeImmutable('2026-10-06')); // un mardi

        self::assertSame(['Wednesday:Mercredi band', 'Saturday:Samedi band', 'Monday:Lundi band'], $this->summary($days));
    }

    #[Test]
    public function testTodayIsFlaggedWhenItHasASlotAndComesFirst(): void
    {
        $slots = [$this->slot('Mardi band', Weekday::Tuesday), $this->slot('Lundi band', Weekday::Monday)];

        $days = (new PlanningDays())->group($slots, new \DateTimeImmutable('2026-10-06'));

        self::assertSame(['Tuesday*:Mardi band', 'Monday:Lundi band'], $this->summary($days));
    }

    #[Test]
    public function testSlotsOfTheSameDayKeepTheirOrderOfStartTime(): void
    {
        $slots = [$this->slot('Tard', Weekday::Friday, '21:00:00'), $this->slot('Tôt', Weekday::Friday, '14:00:00')];

        $days = (new PlanningDays())->group($slots, new \DateTimeImmutable('2026-10-06'));

        self::assertSame(['Friday:Tôt,Tard'], $this->summary($days));
    }

    #[Test]
    public function testNoSlotGivesNoDay(): void
    {
        self::assertSame([], (new PlanningDays())->group([], new \DateTimeImmutable('2026-10-06')));
    }
}
