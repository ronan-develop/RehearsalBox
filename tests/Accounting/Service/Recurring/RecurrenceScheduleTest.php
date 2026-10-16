<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Service\Recurring;

use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\PaymentMethod;
use App\Accounting\Entity\Periodicity;
use App\Accounting\Entity\RecurringTemplate;
use App\Accounting\Service\Recurring\RecurrenceSchedule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #362 : calendrier d'un modèle récurrent : mensuel, trimestriel, annuel ; jour d'échéance ; bornes ; mois courts. */
final class RecurrenceScheduleTest extends TestCase
{
    private function template(Periodicity $p, string $first = '2025-10-01', int $day = 5): RecurringTemplate
    {
        return new RecurringTemplate(1, 'Loyer', Direction::Depense, EntryCategory::Loyer, PaymentMethod::Virement, 49800, 'VIR', $p, $day, new \DateTimeImmutable($first), true);
    }

    /** @return list<string> */
    private function periods(RecurringTemplate $t, string $from, string $to): array
    {
        return array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m'), (new RecurrenceSchedule())->periodsBetween($t, new \DateTimeImmutable($from), new \DateTimeImmutable($to)));
    }

    #[Test]
    public function testAQuarterlyTemplateIsDueEveryThreeMonthsFromItsFirstMonth(): void
    {
        self::assertSame(['2025-10', '2026-01', '2026-04', '2026-07'], $this->periods($this->template(Periodicity::Trimestrielle), '2025-10-01', '2026-09-30'));
    }

    #[Test]
    public function testAMonthlyTemplateIsDueEveryMonthAndAnAnnualOneOnce(): void
    {
        self::assertCount(12, $this->periods($this->template(Periodicity::Mensuelle), '2025-10-01', '2026-09-30'));
        self::assertSame(['2025-10'], $this->periods($this->template(Periodicity::Annuelle), '2025-10-01', '2026-09-30'));
        self::assertSame(['2026-10'], $this->periods($this->template(Periodicity::Annuelle), '2026-10-01', '2027-09-30'));
    }

    #[Test]
    public function testTheDueDayDecidesWhetherAMonthIsReachedYet(): void
    {
        $monthly = $this->template(Periodicity::Mensuelle, '2025-10-01', 5);

        self::assertSame(['2025-10'], $this->periods($monthly, '2025-10-01', '2025-10-05'), 'le 5 inclus');
        self::assertSame([], $this->periods($monthly, '2025-10-01', '2025-10-04'));
    }

    #[Test]
    public function testNothingIsDueBeforeTheFirstMonthOrOutsideTheWindow(): void
    {
        self::assertSame([], $this->periods($this->template(Periodicity::Mensuelle, '2026-03-01'), '2025-10-01', '2026-02-28'));
        self::assertSame(['2026-04'], $this->periods($this->template(Periodicity::Trimestrielle), '2026-04-01', '2026-04-30'));
    }

    #[Test]
    public function testAScheduledMonthIsTheFirstOfAMonthOnTheRhythm(): void
    {
        $s = new RecurrenceSchedule();
        $quarterly = $this->template(Periodicity::Trimestrielle);

        self::assertTrue($s->isScheduled($quarterly, new \DateTimeImmutable('2026-01-01')));
        self::assertFalse($s->isScheduled($quarterly, new \DateTimeImmutable('2026-02-01')));
        self::assertFalse($s->isScheduled($quarterly, new \DateTimeImmutable('2026-01-15')), 'pas un premier du mois');
        self::assertFalse($s->isScheduled($quarterly, new \DateTimeImmutable('2025-07-01')), 'avant le premier mois');
    }

    #[Test]
    public function testTheDueDateStaysInTheMonthWhateverItsLength(): void
    {
        $t = $this->template(Periodicity::Mensuelle, '2026-02-01', 28);

        self::assertSame('2026-02-28', (new RecurrenceSchedule())->dueOn($t, new \DateTimeImmutable('2026-02-01'))->format('Y-m-d'));
    }
}
