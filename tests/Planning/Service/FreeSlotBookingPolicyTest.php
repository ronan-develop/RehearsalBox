<?php

declare(strict_types=1);

namespace App\Tests\Planning\Service;

use App\Planning\Entity\TimeRange;
use App\Planning\Exception\AvailabilityValidationException;
use App\Planning\Service\FreeSlotBookingPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #263 partie 2 : les garde-fous d'une réservation libre (valeurs par défaut du ticket, modifiables en un seul endroit). */
final class FreeSlotBookingPolicyTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-14 10:00:00'); // un mercredi
    }

    private function tomorrow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-15');
    }

    /** @return array<string, string> erreurs par champ, vide si la réservation est permise */
    private function errors(?\DateTimeImmutable $date, ?string $start, ?string $end, ?string $reason = null, int $upcoming = 0): array
    {
        try {
            (new FreeSlotBookingPolicy())->assertAllowed($date ?? $this->tomorrow(), $start, $end, $reason, $upcoming, $this->now);

            return [];
        } catch (AvailabilityValidationException $e) {
            return $e->fields();
        }
    }

    #[Test]
    public function testAValidBookingReturnsItsNormalisedRange(): void
    {
        $range = (new FreeSlotBookingPolicy())->assertAllowed($this->tomorrow(), '09:00', '21:00', 'Répétition', 2, $this->now);

        self::assertEquals(new TimeRange('09:00:00', '21:00:00'), $range, '12 h exactement : permis ; heures normalisées en HH:MM:SS');
    }

    #[Test]
    public function testBothBoundsAreRequiredOnTheQuarterHourStartBeforeEndAndNotAfter2330(): void
    {
        self::assertArrayHasKey('startTime', $this->errors(null, null, null));
        self::assertArrayHasKey('startTime', $this->errors(null, '09:00', null));
        self::assertArrayHasKey('startTime', $this->errors(null, '09:10', '10:00'));
        self::assertArrayHasKey('endTime', $this->errors(null, '09:00', '10:20'));
        self::assertArrayHasKey('endTime', $this->errors(null, '10:00', '09:00'), 'fin avant début');
        self::assertArrayHasKey('endTime', $this->errors(null, '10:00', '10:00'), 'durée nulle');
        self::assertArrayHasKey('endTime', $this->errors(null, '20:00', '23:45'), 'au-delà de 23h30');
        self::assertSame([], $this->errors(null, '20:00', '23:30'), '23h30 : permis');
    }

    #[Test]
    public function testAReservationLastsAtMostTwelveHours(): void
    {
        self::assertSame([], $this->errors(null, '08:00', '20:00'));
        self::assertArrayHasKey('endTime', $this->errors(null, '08:00', '20:15'));
    }

    #[Test]
    public function testTheDateMustBeFutureWithinThirtyDaysAndTodayOnlyIfItHasNotStarted(): void
    {
        self::assertArrayHasKey('bookingDate', $this->errors(new \DateTimeImmutable('2026-10-13'), '18:00', '20:00'), 'hier');
        self::assertArrayHasKey('startTime', $this->errors(new \DateTimeImmutable('2026-10-14'), '09:00', '11:00'), 'aujourd\'hui, déjà commencé');
        self::assertSame([], $this->errors(new \DateTimeImmutable('2026-10-14'), '18:00', '20:00'), 'aujourd\'hui, plus tard');
        self::assertSame([], $this->errors(new \DateTimeImmutable('2026-11-13'), '18:00', '20:00'), 'dans 30 jours');
        self::assertArrayHasKey('bookingDate', $this->errors(new \DateTimeImmutable('2026-11-14'), '18:00', '20:00'), 'dans 31 jours');
    }

    #[Test]
    public function testAGroupMayHoldAtMostThreeUpcomingBookingsAndTheReasonMustFit(): void
    {
        self::assertSame([], $this->errors(null, '18:00', '20:00', null, 2));
        self::assertArrayHasKey('group', $this->errors(null, '18:00', '20:00', null, 3));
        self::assertSame([], $this->errors(null, '18:00', '20:00', str_repeat('x', 255)));
        self::assertArrayHasKey('reason', $this->errors(null, '18:00', '20:00', str_repeat('x', 256)));
    }

    #[Test]
    public function testSeveralProblemsAreReportedTogether(): void
    {
        $errors = $this->errors(new \DateTimeImmutable('2026-10-01'), '09:10', '09:00', str_repeat('x', 300), 3);

        self::assertEqualsCanonicalizing(['bookingDate', 'startTime', 'reason', 'group'], array_keys($errors));
    }
}
