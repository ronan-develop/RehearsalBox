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

    // --- Créneaux occasionnels dans le planning (#461) ------------------------------------------------------------------

    private function fixed(int $id, string $group, Weekday $weekday, string $start = '18:00:00', string $end = '20:00:00'): RequestableSlot
    {
        return new RequestableSlot(new RecurringSlot($id, $id, $weekday, $start, $end, true), $group, $id);
    }

    /** Une occurrence ponctuelle : le groupe demandeur prend (une partie du) créneau du titulaire `$holderSlotId`, ou une réservation libre (id 0). */
    private function occasional(int $holderSlotId, string $requester, Weekday $weekday, string $date, string $start = '18:00:00', string $end = '20:00:00'): RequestableSlot
    {
        return RequestableSlot::occasional(new RecurringSlot($holderSlotId, 99, $weekday, $start, $end, true), $requester, 99, new \DateTimeImmutable($date));
    }

    #[Test]
    public function testOccasionalSlotsJoinTheirDayAndAreSortedByStartTimeAmongTheFixedOnes(): void
    {
        $days = (new PlanningDays())->group(
            [$this->fixed(1, 'Alpha', Weekday::Wednesday, '18:00:00')],
            new \DateTimeImmutable('2026-10-06'), // un mardi
            [$this->occasional(0, 'Tôt', Weekday::Wednesday, '2026-10-07', '10:00:00', '12:00:00'), $this->occasional(0, 'Tard', Weekday::Wednesday, '2026-10-07', '21:00:00', '22:00:00')],
        );

        self::assertSame(['Wednesday:Tôt,Alpha,Tard'], $this->summary($days));
    }

    #[Test]
    public function testAnOccasionalSlotBeforeTodayIsLeftOutButTodayIsKept(): void
    {
        $days = (new PlanningDays())->group(
            [],
            new \DateTimeImmutable('2026-10-06'), // mardi
            [$this->occasional(0, 'Hier', Weekday::Monday, '2026-10-05'), $this->occasional(0, 'Aujourdhui', Weekday::Tuesday, '2026-10-06')],
        );

        self::assertSame(['Tuesday*:Aujourdhui'], $this->summary($days), 'un lundi passé ne devient pas « lundi prochain »');
    }

    #[Test]
    public function testAnAcceptedExchangeMarksTheHoldersFixedSlotAsCededToTheRequester(): void
    {
        $holder = $this->fixed(1, 'Depece', Weekday::Wednesday);
        $days = (new PlanningDays())->group([$holder], new \DateTimeImmutable('2026-10-06'), [$this->occasional(1, 'Office', Weekday::Wednesday, '2026-10-07')]);

        $cessions = $days[0]->cessionsFor($holder);

        self::assertCount(1, $cessions);
        self::assertSame('Office', $cessions[0]->groupName());
        self::assertSame('2026-10-07', $cessions[0]->date()->format('Y-m-d'));
        self::assertNull($cessions[0]->partialRange(), 'tout le créneau : pas de plage à préciser');
    }

    #[Test]
    public function testAPartialExchangeNamesTheCededRange(): void
    {
        $holder = $this->fixed(1, 'Depece', Weekday::Wednesday, '18:30:00', '22:45:00');
        $days = (new PlanningDays())->group([$holder], new \DateTimeImmutable('2026-10-06'), [$this->occasional(1, 'Office', Weekday::Wednesday, '2026-10-07', '19:00:00', '20:30:00')]);

        self::assertSame('19:00 – 20:30', $days[0]->cessionsFor($holder)[0]->partialRange());
    }

    #[Test]
    public function testAFreeBookingOrAnotherGroupsExchangeNeverMarksAFixedSlot(): void
    {
        $holder = $this->fixed(1, 'Depece', Weekday::Wednesday);
        $days = (new PlanningDays())->group(
            [$holder],
            new \DateTimeImmutable('2026-10-06'),
            [$this->occasional(0, 'Libre', Weekday::Wednesday, '2026-10-07'), $this->occasional(2, 'Autre', Weekday::Wednesday, '2026-10-07')],
        );

        self::assertSame([], $days[0]->cessionsFor($holder));
        self::assertCount(3, $days[0]->slots(), 'les deux occasionnels s\'affichent quand même');
    }
}
