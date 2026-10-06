<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Enum\FreeSlotBookingStatus;
use App\Entity\Requester;
use App\Entity\TimeRange;
use App\Repository\MysqlFreeSlotBookingRepository;
use App\Tests\RepositoryTestCase;
use App\Tests\Support\MessagingScenario;
use PHPUnit\Framework\Attributes\Test;

/** #263 partie 2 : réservations libres du local. */
final class MysqlFreeSlotBookingRepositoryTest extends RepositoryTestCase
{
    use MessagingScenario;

    private MysqlFreeSlotBookingRepository $bookings;
    private Requester $alpha;
    private int $alphaGroup;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $this->bookings = new MysqlFreeSlotBookingRepository($this->pdo);
        $alice = $this->user('Alice');
        $this->alphaGroup = $this->group('Alpha', $alice)->id();
        $this->alpha = new Requester($this->alphaGroup, $alice->id());
        $this->adminId = $this->user('Admin')->id();
    }

    private function day(string $modifier = '+1 day'): \DateTimeImmutable
    {
        return $this->now->modify($modifier)->setTime(0, 0);
    }

    private function book(string $start = '09:00:00', string $end = '12:00:00', string $date = '+1 day', ?Requester $who = null, ?string $reason = null): \App\Entity\FreeSlotBooking
    {
        return $this->bookings->create($who ?? $this->alpha, $this->day($date), new TimeRange($start, $end), $reason);
    }

    #[Test]
    public function testACreatedBookingIsPendingAndRoundTrips(): void
    {
        $created = $this->book('09:00:00', '12:00:00', '+1 day', null, 'Enregistrement');

        $found = $this->bookings->findById($created->id());

        self::assertNotNull($found);
        self::assertSame(FreeSlotBookingStatus::EnAttente, $found->status());
        self::assertSame($this->alphaGroup, $found->requester()->groupId());
        self::assertSame($this->day()->format('Y-m-d'), $found->date()->format('Y-m-d'));
        self::assertEquals(new TimeRange('09:00:00', '12:00:00'), $found->range());
        self::assertSame('Enregistrement', $found->reason());
        self::assertNull($found->decisionNote());
        self::assertTrue($found->occupies());
        self::assertNull($this->bookings->findById(999999));
    }

    #[Test]
    public function testOnlyPendingOrValidatedBookingsOfTheSameDayThatOverlapAreFound(): void
    {
        $pending = $this->book('09:00:00', '12:00:00');
        $validated = $this->book('14:00:00', '16:00:00');
        $this->bookings->decide($validated->id(), FreeSlotBookingStatus::Validee, $this->adminId, null, $this->now);
        $refused = $this->book('18:00:00', '20:00:00');
        $this->bookings->decide($refused->id(), FreeSlotBookingStatus::Refusee, $this->adminId, 'Local fermé', $this->now);
        $this->book('09:00:00', '12:00:00', '+2 days');

        $ids = static fn (array $list): array => array_map(static fn ($b): int => $b->id(), $list);
        self::assertSame([$pending->id()], $ids($this->bookings->findOverlapping($this->day(), new TimeRange('11:00:00', '13:00:00'))), 'chevauchement partiel');
        self::assertSame([$pending->id()], $ids($this->bookings->findOverlapping($this->day(), new TimeRange('09:00:00', '12:00:00'))), 'exact');
        self::assertSame([], $ids($this->bookings->findOverlapping($this->day(), new TimeRange('12:00:00', '14:00:00'))), 'contigu des deux côtés : libre');
        self::assertSame([$validated->id()], $ids($this->bookings->findOverlapping($this->day(), new TimeRange('15:00:00', '15:30:00'))), 'une réservation validée occupe aussi');
        self::assertSame([], $ids($this->bookings->findOverlapping($this->day(), new TimeRange('18:30:00', '19:00:00'))), 'une réservation refusée ne bloque plus');
    }

    #[Test]
    public function testItCountsOnlyTheUpcomingActiveBookingsOfTheGroup(): void
    {
        $this->book('09:00:00', '10:00:00', '+1 day');
        $this->book('09:00:00', '10:00:00', '+2 days');
        $cancelled = $this->book('09:00:00', '10:00:00', '+3 days');
        $this->bookings->cancel($cancelled->id(), $this->now);
        $this->book('09:00:00', '10:00:00', '-1 day'); // passée
        $other = new Requester($this->group('Beta', $b = $this->user('Bob'))->id(), $b->id());
        $this->book('09:00:00', '10:00:00', '+4 days', $other);

        self::assertSame(2, $this->bookings->countUpcomingFor($this->alphaGroup, $this->now));
    }

    #[Test]
    public function testOnlyThePendingBookingCanBeDecidedAndOnlyOnce(): void
    {
        $booking = $this->book();

        self::assertTrue($this->bookings->decide($booking->id(), FreeSlotBookingStatus::Refusee, $this->adminId, 'Travaux', $this->now));
        self::assertFalse($this->bookings->decide($booking->id(), FreeSlotBookingStatus::Validee, $this->adminId, null, $this->now), 'déjà traitée : le second admin perd');

        $decided = $this->bookings->findById($booking->id());
        self::assertSame(FreeSlotBookingStatus::Refusee, $decided?->status());
        self::assertSame('Travaux', $decided?->decisionNote());
        self::assertFalse($decided?->occupies());
    }

    #[Test]
    public function testABookingCanBeCancelledUntilItStartsButNotAfterNorTwice(): void
    {
        $pending = $this->book('09:00:00', '10:00:00', '+1 day');
        $validated = $this->book('11:00:00', '12:00:00', '+1 day');
        $this->bookings->decide($validated->id(), FreeSlotBookingStatus::Validee, $this->adminId, null, $this->now);
        $started = $this->book('09:00:00', '11:00:00', '+0 day'); // aujourd'hui 09h, il est 12h

        self::assertTrue($this->bookings->cancel($pending->id(), $this->now));
        self::assertTrue($this->bookings->cancel($validated->id(), $this->now), 'une réservation validée peut aussi être annulée');
        self::assertFalse($this->bookings->cancel($pending->id(), $this->now), 'déjà annulée');
        self::assertFalse($this->bookings->cancel($started->id(), $this->now), 'déjà commencée');
        self::assertSame(FreeSlotBookingStatus::Annulee, $this->bookings->findById($pending->id())?->status());
    }

    #[Test]
    public function testPendingBookingsAreListedByDateThenStartAndAGroupSeesItsOwn(): void
    {
        $later = $this->book('09:00:00', '10:00:00', '+3 days');
        $second = $this->book('14:00:00', '15:00:00', '+1 day');
        $first = $this->book('09:00:00', '10:00:00', '+1 day');
        $other = new Requester($this->group('Beta', $b = $this->user('Bob'))->id(), $b->id());
        $foreign = $this->book('09:00:00', '10:00:00', '+2 days', $other);

        $ids = static fn (array $list): array => array_map(static fn ($x): int => $x->id(), $list);
        self::assertSame([$first->id(), $second->id(), $foreign->id(), $later->id()], $ids($this->bookings->findPending()));
        self::assertSame([$first->id(), $second->id(), $later->id()], $ids($this->bookings->findForGroup($this->alphaGroup, $this->now)));
    }

    #[Test]
    public function testValidatedBookingsOfAPeriodAreListedForTheWeekView(): void
    {
        $in = $this->book('09:00:00', '10:00:00', '+1 day');
        $this->bookings->decide($in->id(), FreeSlotBookingStatus::Validee, $this->adminId, null, $this->now);
        $this->book('11:00:00', '12:00:00', '+1 day'); // en attente : absente
        $out = $this->book('09:00:00', '10:00:00', '+20 days');
        $this->bookings->decide($out->id(), FreeSlotBookingStatus::Validee, $this->adminId, null, $this->now);

        $found = $this->bookings->findValidatedBetween($this->day('+0 day'), $this->day('+7 days'));

        self::assertSame([$in->id()], array_map(static fn ($b): int => $b->id(), $found));
    }

    #[Test]
    public function testTheDatabaseRefusesABackwardsRangeAndTheBookingsLeaveWithTheirGroup(): void
    {
        try {
            $this->pdo->exec("INSERT INTO free_slot_bookings (group_id, booked_by_user_id, booking_date, start_time, end_time, status) VALUES ({$this->alphaGroup}, {$this->alpha->userId()}, '2026-10-20', '12:00:00', '10:00:00', 'en_attente')");
            self::fail('une plage à l\'envers doit être refusée par la base');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        $booking = $this->book();
        $this->groups->delete($this->alphaGroup);
        self::assertNull($this->bookings->findById($booking->id()), 'la réservation part avec son groupe');
    }
}
