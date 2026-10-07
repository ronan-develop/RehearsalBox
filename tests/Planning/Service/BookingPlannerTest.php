<?php

declare(strict_types=1);

namespace App\Tests\Planning\Service;

use App\Planning\Entity\FreeSlotBookingStatus;
use App\Planning\Entity\Weekday;
use App\Planning\Entity\RecurringSlot;
use App\Planning\Entity\Requester;
use App\Planning\Entity\TimeRange;
use App\Planning\Repository\MysqlFreeSlotBookingRepository;
use App\Planning\Repository\MysqlRecurringSlotRepository;
use App\Planning\Service\BookingPlanner;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Scenarios\MessagingScenario;
use PHPUnit\Framework\Attributes\Test;

/** #263 partie 3b-1 : ce qui est libre et ce qui chevauche un autre groupe, pour une plage voulue. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class BookingPlannerTest extends RepositoryTestCase
{
    use MessagingScenario;

    private BookingPlanner $planner;
    private \Symfony\Component\Clock\MockClock $clock;
    private MysqlRecurringSlotRepository $slots;
    private MysqlFreeSlotBookingRepository $bookings;
    private int $alpha;
    private int $beta;
    private int $aliceId;
    private int $bobId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        [$this->aliceId, $this->bobId] = [$alice->id(), $bob->id()];
        $this->alpha = $this->group('Alpha', $alice)->id();
        $this->beta = $this->group('The Office', $bob)->id();
        $this->slots = new MysqlRecurringSlotRepository($this->pdo);
        $this->bookings = new MysqlFreeSlotBookingRepository($this->pdo);
        $this->clock = new \Symfony\Component\Clock\MockClock('2026-10-04 12:00:00');
        $this->planner = new BookingPlanner($this->slots, $this->groups, $this->bookings, new \App\Planning\Service\FreeSlotBookingPolicy(), $this->clock);
    }

    private function wednesday(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-07');
    }

    /** @param list<TimeRange> $ranges @return list<string> */
    private function show(array $ranges): array
    {
        return array_map(static fn (TimeRange $r): string => substr($r->start(), 0, 5) . '-' . substr($r->end(), 0, 5), $ranges);
    }

    #[Test]
    public function testTheExampleOfTheOwnerWednesdayUntil19hAgainstTheOfficeFrom18h30(): void
    {
        $this->slots->save(new RecurringSlot(0, $this->beta, Weekday::Wednesday, '18:30:00', '22:45:00', true));

        $plan = $this->planner->plan($this->alpha, $this->wednesday(), new TimeRange('09:00:00', '19:00:00'));

        self::assertSame(['09:00-18:30'], $this->show($plan->freeParts()), 'la partie libre à réserver');
        self::assertFalse($plan->isFullyFree());
        [$conflict] = $plan->conflicts();
        self::assertSame(['fixed', 'The Office', false], [$conflict->kind(), $conflict->groupName(), $conflict->isOwn()]);
        self::assertSame('18:30-19:00', $this->show([$conflict->overlap()])[0], 'la plage à demander au titulaire');
        self::assertNotNull($conflict->slotId());
    }

    #[Test]
    public function testAFreeDayIsFullyFreeWithNoConflict(): void
    {
        $this->slots->save(new RecurringSlot(0, $this->beta, Weekday::Thursday, '18:30:00', '22:45:00', true));

        $plan = $this->planner->plan($this->alpha, $this->wednesday(), new TimeRange('09:00:00', '19:00:00'));

        self::assertTrue($plan->isFullyFree());
        self::assertSame(['09:00-19:00'], $this->show($plan->freeParts()));
        self::assertSame([], $plan->conflicts());
    }

    #[Test]
    public function testARangeEntirelyInsideAnotherGroupsSlotHasNoFreePartOnlyARequest(): void
    {
        $this->slots->save(new RecurringSlot(0, $this->beta, Weekday::Wednesday, '18:30:00', '22:45:00', true));

        $plan = $this->planner->plan($this->alpha, $this->wednesday(), new TimeRange('19:00:00', '21:00:00'));

        self::assertSame([], $plan->freeParts());
        self::assertSame(['19:00-21:00'], $this->show(array_map(static fn ($c): TimeRange => $c->overlap(), $plan->conflicts())));
    }

    #[Test]
    public function testASlotInTheMiddleSplitsTheFreePartsAndSeveralSlotsGiveSeveralConflicts(): void
    {
        $this->slots->save(new RecurringSlot(0, $this->beta, Weekday::Wednesday, '12:00:00', '14:00:00', true));
        $this->slots->save(new RecurringSlot(0, $this->beta, Weekday::Wednesday, '18:30:00', '22:45:00', true));

        $plan = $this->planner->plan($this->alpha, $this->wednesday(), new TimeRange('09:00:00', '20:00:00'));

        self::assertSame(['09:00-12:00', '14:00-18:30'], $this->show($plan->freeParts()));
        self::assertSame(['12:00-14:00', '18:30-20:00'], $this->show(array_map(static fn ($c): TimeRange => $c->overlap(), $plan->conflicts())), 'dans l\'ordre de la journée');
    }

    #[Test]
    public function testTheGroupsOwnFixedSlotIsFlaggedOwnSoNothingIsAskedOfItselfButItIsNotBookable(): void
    {
        $this->slots->save(new RecurringSlot(0, $this->alpha, Weekday::Wednesday, '14:00:00', '16:00:00', true));

        $plan = $this->planner->plan($this->alpha, $this->wednesday(), new TimeRange('13:00:00', '17:00:00'));

        self::assertSame(['13:00-14:00', '16:00-17:00'], $this->show($plan->freeParts()), 'le créneau déjà détenu n\'est pas à réserver');
        self::assertTrue($plan->conflicts()[0]->isOwn());
    }

    #[Test]
    public function testAnInactiveFixedSlotAndATouchingOneDoNotConflict(): void
    {
        $this->slots->save(new RecurringSlot(0, $this->beta, Weekday::Wednesday, '09:00:00', '12:00:00', false));
        $this->slots->save(new RecurringSlot(0, $this->beta, Weekday::Wednesday, '19:00:00', '22:00:00', true));

        $plan = $this->planner->plan($this->alpha, $this->wednesday(), new TimeRange('09:00:00', '19:00:00'));

        self::assertTrue($plan->isFullyFree());
    }

    #[Test]
    public function testAnExistingPendingOrValidatedBookingIsReportedButNeverRequestable(): void
    {
        $this->bookings->create(new Requester($this->beta, $this->bobId), $this->wednesday(), new TimeRange('10:00:00', '12:00:00'), null);
        $refused = $this->bookings->create(new Requester($this->beta, $this->bobId), $this->wednesday(), new TimeRange('15:00:00', '16:00:00'), null);
        $this->bookings->decide($refused->id(), FreeSlotBookingStatus::Refusee, $this->bobId, null, $this->now);

        $plan = $this->planner->plan($this->alpha, $this->wednesday(), new TimeRange('09:00:00', '17:00:00'));

        self::assertSame(['09:00-10:00', '12:00-17:00'], $this->show($plan->freeParts()), 'une réservation refusée ne bloque plus');
        [$conflict] = $plan->conflicts();
        self::assertSame(['booking', null, 'The Office'], [$conflict->kind(), $conflict->slotId(), $conflict->groupName()]);
        self::assertSame('10:00-12:00', $this->show([$conflict->overlap()])[0]);
    }

    #[Test]
    public function testPlanForChecksMembershipThenThePolicyThenPlans(): void
    {
        $this->slots->save(new RecurringSlot(0, $this->beta, Weekday::Wednesday, '18:30:00', '22:45:00', true));

        $plan = $this->planner->planFor($this->aliceId, $this->alpha, $this->wednesday(), '09:00', '19:00');
        self::assertSame(['09:00-18:30'], $this->show($plan->freeParts()));

        foreach ([[$this->bobId, $this->alpha], [$this->aliceId, $this->beta], [$this->aliceId, 999999]] as [$user, $group]) {
            try {
                $this->planner->planFor($user, $group, $this->wednesday(), '09:00', '10:00');
                self::fail('accès refusé attendu');
            } catch (\App\Security\Exception\AccessDeniedException $e) {
                self::assertSame('Accès refusé.', $e->getMessage());
            }
        }

        try {
            $this->planner->planFor($this->aliceId, $this->alpha, $this->wednesday(), '08:00', '21:00'); // 13 h
            self::fail('règle de la politique attendue');
        } catch (\App\Planning\Exception\AvailabilityValidationException $e) {
            self::assertArrayHasKey('endTime', $e->fields());
        }
    }
}
