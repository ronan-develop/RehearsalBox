<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\FreeSlotBookingStatus;
use App\Entity\Enum\Weekday;
use App\Entity\RecurringSlot;
use App\Repository\MysqlFreeSlotBookingRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Security\Exception\AccessDeniedException;
use App\Service\Exception\AvailabilityValidationException;
use App\Service\Exception\FreeSlotBookingConflictException;
use App\Service\Exception\RequestAlreadyRespondedException;
use App\Service\FreeSlotBookingPolicy;
use App\Service\FreeSlotBookingService;
use App\Tests\RepositoryTestCase;
use App\Tests\Support\MessagingScenario;
use App\Tests\TestDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

/** #263 partie 2 : réserver un créneau libre, validé par un administrateur. */
final class FreeSlotBookingServiceTest extends RepositoryTestCase
{
    use MessagingScenario;

    private MockClock $clock;
    private FreeSlotBookingService $service;
    private MysqlFreeSlotBookingRepository $bookings;
    private MysqlRecurringSlotRepository $slots;
    private int $alice;
    private int $bob;
    private int $stranger;
    private int $admin;
    private int $alpha;
    private int $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $this->clock = new MockClock('2026-10-04 12:00:00'); // un dimanche ; le mercredi suivant est le 2026-10-07
        $this->bookings = new MysqlFreeSlotBookingRepository($this->pdo);
        $this->slots = new MysqlRecurringSlotRepository($this->pdo);
        [$alice, $bob, $carole, $admin] = [$this->user('Alice'), $this->user('Bob'), $this->user('Carole'), $this->user('Admin')];
        [$this->alice, $this->bob, $this->stranger, $this->admin] = [$alice->id(), $bob->id(), $carole->id(), $admin->id()];
        $this->alpha = $this->group('Alpha', $alice)->id();
        $this->beta = $this->group('Beta', $bob)->id();
        $this->slots->save(new RecurringSlot(0, $this->beta, Weekday::Wednesday, '18:30:00', '22:45:00', true));
        $this->service = new FreeSlotBookingService($this->bookings, $this->slots, $this->groups, new FreeSlotBookingPolicy(), $this->clock);
    }

    private function wednesday(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-07');
    }

    #[Test]
    public function testAMemberBooksAFreeRangeForTheirGroupAndItWaitsForAnAdmin(): void
    {
        $booking = $this->service->request($this->alice, $this->alpha, $this->wednesday(), '09:00', '14:00', 'Enregistrement');

        self::assertSame(FreeSlotBookingStatus::EnAttente, $booking->status());
        self::assertSame($this->alpha, $booking->requester()->groupId());
        self::assertSame($this->alice, $booking->requester()->userId());
        self::assertSame(['09:00:00', '14:00:00'], [$booking->range()->start(), $booking->range()->end()]);
    }

    #[Test]
    public function testOnlyAMemberOfTheGroupMayBookForIt(): void
    {
        foreach ([[$this->stranger, $this->alpha], [$this->alice, $this->beta], [$this->alice, 999999]] as [$user, $group]) {
            try {
                $this->service->request($user, $group, $this->wednesday(), '09:00', '10:00', null);
                self::fail('accès refusé attendu');
            } catch (AccessDeniedException $e) {
                self::assertSame('Accès refusé.', $e->getMessage());
            }
        }
        self::assertSame([], $this->bookings->findPending());
    }

    #[Test]
    public function testThePolicyIsApplied(): void
    {
        $this->expectException(AvailabilityValidationException::class);

        $this->service->request($this->alice, $this->alpha, $this->wednesday(), '08:00', '21:00', null); // 13 h
    }

    #[Test]
    public function testItNeverOverlapsAFixedSlotOfAnyGroupButMayTouchItOrUseAnotherDay(): void
    {
        foreach ([['18:00', '19:00'], ['20:00', '21:00'], ['22:00', '23:00'], ['18:30', '22:45']] as [$start, $end]) {
            try {
                $this->service->request($this->alice, $this->alpha, $this->wednesday(), $start, $end, null);
                self::fail("chevauchement attendu : {$start}-{$end}");
            } catch (FreeSlotBookingConflictException $e) {
                self::assertStringNotContainsString('Beta', $e->getMessage(), 'ne nomme pas le groupe titulaire');
            }
        }

        self::assertSame('18:30:00', $this->service->request($this->alice, $this->alpha, $this->wednesday(), '09:00', '18:30', null)->range()->end(), 'contigu : libre');
        self::assertSame('Thursday', $this->service->request($this->alice, $this->alpha, new \DateTimeImmutable('2026-10-08'), '18:30', '22:45', null)->date()->format('l'), 'un autre jour de semaine');
    }

    #[Test]
    public function testAnInactiveFixedSlotNoLongerBlocks(): void
    {
        $this->slots->save(new RecurringSlot(0, $this->beta, Weekday::Friday, '18:00:00', '20:00:00', false));

        self::assertSame('18:00:00', $this->service->request($this->alice, $this->alpha, new \DateTimeImmutable('2026-10-09'), '18:00', '20:00', null)->range()->start());
    }

    #[Test]
    public function testAPendingOrValidatedBookingBlocksItsRangeUntilRefusedOrCancelled(): void
    {
        $first = $this->service->request($this->alice, $this->alpha, $this->wednesday(), '09:00', '12:00', null);

        $this->expectBlocked('10:00', '11:00');
        $this->service->refuse($this->admin, $first->id(), 'Local fermé');
        self::assertSame('10:00:00', $this->service->request($this->alice, $this->alpha, $this->wednesday(), '10:00', '11:00', null)->range()->start(), 'refusée : la plage est libre');
    }

    private function expectBlocked(string $start, string $end): void
    {
        try {
            $this->service->request($this->alice, $this->alpha, $this->wednesday(), $start, $end, null);
            self::fail('plage déjà réservée');
        } catch (FreeSlotBookingConflictException) {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function testAGroupMayHoldThreeUpcomingBookingsOnly(): void
    {
        foreach (['09:00', '10:00', '11:00'] as $start) {
            $this->service->request($this->alice, $this->alpha, $this->wednesday(), $start, substr_replace($start, (string) ((int) $start + 1), 0, 2), null);
        }

        try {
            $this->service->request($this->alice, $this->alpha, $this->wednesday(), '13:00', '14:00', null);
            self::fail('quota atteint');
        } catch (AvailabilityValidationException $e) {
            self::assertArrayHasKey('group', $e->fields());
        }
    }

    #[Test]
    public function testAMemberCancelsBeforeItStartsNeverAStrangerNorTwice(): void
    {
        $booking = $this->service->request($this->alice, $this->alpha, $this->wednesday(), '09:00', '12:00', null);

        try {
            $this->service->cancel($this->stranger, $booking->id());
            self::fail('accès refusé attendu');
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }
        $this->service->cancel($this->alice, $booking->id());
        self::assertSame(FreeSlotBookingStatus::Annulee, $this->bookings->findById($booking->id())?->status());

        $this->expectException(RequestAlreadyRespondedException::class);
        $this->service->cancel($this->alice, $booking->id());
    }

    #[Test]
    public function testAnUnknownBookingIsRefusedLikeAForbiddenOne(): void
    {
        foreach ([fn () => $this->service->cancel($this->alice, 999999), fn () => $this->service->approve($this->admin, 999999), fn () => $this->service->refuse($this->admin, 999999, null)] as $call) {
            try {
                $call();
                self::fail('accès refusé attendu');
            } catch (AccessDeniedException $e) {
                self::assertSame('Accès refusé.', $e->getMessage());
            }
        }
    }

    #[Test]
    public function testTheFirstAdminToAnswerWinsAndARefusalMayCarryAnOptionalNote(): void
    {
        $booking = $this->service->request($this->alice, $this->alpha, $this->wednesday(), '09:00', '12:00', null);

        $approved = $this->service->approve($this->admin, $booking->id());
        self::assertSame(FreeSlotBookingStatus::Validee, $approved->status());

        $this->expectException(RequestAlreadyRespondedException::class);
        $this->service->refuse($this->admin, $booking->id(), 'trop tard');
    }

    #[Test]
    public function testARefusalNoteMustFitAndIsOptional(): void
    {
        $one = $this->service->request($this->alice, $this->alpha, $this->wednesday(), '09:00', '10:00', null);
        $two = $this->service->request($this->alice, $this->alpha, $this->wednesday(), '11:00', '12:00', null);

        self::assertNull($this->service->refuse($this->admin, $one->id(), null)->decisionNote());
        $this->expectException(AvailabilityValidationException::class);
        $this->service->refuse($this->admin, $two->id(), str_repeat('x', 256));
    }

    #[Test]
    public function testApprovalChecksAgainstFixedSlotsAgainBecauseOneMayHaveBeenAddedSince(): void
    {
        $booking = $this->service->request($this->alice, $this->alpha, $this->wednesday(), '09:00', '12:00', null);
        $this->slots->save(new RecurringSlot(0, $this->beta, Weekday::Wednesday, '10:00:00', '13:00:00', true)); // ajouté après la demande

        $this->expectException(FreeSlotBookingConflictException::class);
        $this->service->approve($this->admin, $booking->id());
    }

    #[Test]
    public function testABookingWaitsForTheDateLockAndFailsCleanlyIfItIsStillHeld(): void
    {
        $other = new MysqlFreeSlotBookingRepository(TestDatabase::connection());
        $service = new FreeSlotBookingService($this->bookings, $this->slots, $this->groups, new FreeSlotBookingPolicy(), $this->clock, lockWaitSeconds: 0);
        self::assertTrue($other->acquireDateLock($this->wednesday()));
        try {
            $this->expectException(FreeSlotBookingConflictException::class);
            $service->request($this->alice, $this->alpha, $this->wednesday(), '09:00', '10:00', null);
        } finally {
            $other->releaseDateLock($this->wednesday());
        }
    }

    #[Test]
    public function testTheQueriesForAdminsAndGroups(): void
    {
        $mine = $this->service->request($this->alice, $this->alpha, $this->wednesday(), '09:00', '10:00', null);

        self::assertSame([$mine->id()], array_map(static fn ($b): int => $b->id(), $this->service->pending()));
        self::assertSame([$mine->id()], array_map(static fn ($b): int => $b->id(), $this->service->forGroup($this->alice, $this->alpha)));
        $this->expectException(AccessDeniedException::class);
        $this->service->forGroup($this->stranger, $this->alpha);
    }
}
