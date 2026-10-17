<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Todo;

use App\Accounting\Entity\FiscalYear;
use App\Accounting\Entity\FiscalYearStatus;
use App\Accounting\Entity\Statement;
use App\Accounting\Service\StatementReview;
use App\Accounting\Todo\TodoPlanner;
use App\Accounting\Todo\TodoState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #362 livraison 8 : « À faire » du trésorier calculé depuis l'état du livre : mois écoulés, échéance le 5, retard, clôture et approbation. */
final class TodoPlannerTest extends TestCase
{
    private function year(FiscalYearStatus $status = FiscalYearStatus::Ouvert): FiscalYear
    {
        return new FiscalYear(1, '2025-2026', new \DateTimeImmutable('2025-10-01'), new \DateTimeImmutable('2026-09-30'), $status, 10000, null, 1, null, 0);
    }

    private function review(string $start, string $end, ?int $balance = null, int $ledger = 0, ?int $replacedBy = null): StatementReview
    {
        $statement = new Statement(1, 1, new \DateTimeImmutable($start), new \DateTimeImmutable($end), $balance, 'r.pdf', str_repeat('a', 32), 'application/pdf', 10, str_repeat('b', 64), $replacedBy, new \DateTimeImmutable('2025-11-01'));

        return new StatementReview($statement, $ledger, $balance === null ? null : $balance - $ledger);
    }

    /**
     * @param list<StatementReview> $reviews
     * @param list<string> $entryMonths
     * @param list<string> $emptyMonths
     * @return array<string, array{0: string, 1: string}> clé => [état, échéance]
     */
    private function plan(string $today, array $reviews = [], array $entryMonths = [], array $emptyMonths = [], int $recurring = 0, ?FiscalYear $year = null): array
    {
        $items = (new TodoPlanner())->plan($year ?? $this->year(), new \DateTimeImmutable($today), $reviews, array_fill_keys($entryMonths, true), array_fill_keys($emptyMonths, true), $recurring);
        $out = [];
        foreach ($items as $item) {
            $out[$item->key] = [$item->state->value, $item->dueOn?->format('Y-m-d') ?? ''];
        }

        return $out;
    }

    #[Test]
    public function testNothingIsDueWhileTheMonthIsStillRunning(): void
    {
        self::assertSame([], $this->plan('2025-10-20'));
    }

    #[Test]
    public function testAFinishedMonthIsToDoUntilTheFifthThenLate(): void
    {
        $onTime = $this->plan('2025-11-05');
        self::assertSame(['a_faire', '2025-11-05'], $onTime['statement:2025-10']);
        self::assertSame(['a_faire', '2025-11-05'], $onTime['entries:2025-10']);
        $late = $this->plan('2025-11-06');
        self::assertSame('en_retard', $late['statement:2025-10'][0]);
        self::assertSame('en_retard', $late['entries:2025-10'][0]);
    }

    #[Test]
    public function testADepositedStatementAnEntryOrAnEmptyMarkClearsTheMonth(): void
    {
        $plan = $this->plan('2025-12-20', [$this->review('2025-10-01', '2025-10-31')], ['2025-10'], ['2025-11']);

        self::assertArrayNotHasKey('statement:2025-10', $plan);
        self::assertArrayNotHasKey('entries:2025-10', $plan);
        self::assertArrayNotHasKey('entries:2025-11', $plan, 'mois déclaré vide');
        self::assertArrayHasKey('statement:2025-11', $plan);
    }

    #[Test]
    public function testAStatementCoversTheMonthItEndsIn(): void
    {
        $plan = $this->plan('2025-12-20', [$this->review('2025-10-29', '2025-11-28')]);

        self::assertArrayHasKey('statement:2025-10', $plan, 'ce relevé finit en novembre');
        self::assertArrayNotHasKey('statement:2025-11', $plan);
    }

    #[Test]
    public function testAReplacedStatementDoesNotCount(): void
    {
        $plan = $this->plan('2025-11-20', [$this->review('2025-10-01', '2025-10-31', null, 0, 9)]);

        self::assertArrayHasKey('statement:2025-10', $plan);
    }

    #[Test]
    public function testAGapBetweenTheStatementAndTheLedgerIsAnItemUntilItIsZero(): void
    {
        $plan = $this->plan('2025-11-04', [$this->review('2025-10-01', '2025-10-31', 15000, 14000)], ['2025-10']);
        self::assertSame(['a_faire', '2025-11-05'], $plan['gap:2025-10']);
        self::assertSame('en_retard', $this->plan('2025-11-09', [$this->review('2025-10-01', '2025-10-31', 15000, 14000)], ['2025-10'])['gap:2025-10'][0]);
        self::assertArrayNotHasKey('gap:2025-10', $this->plan('2025-11-09', [$this->review('2025-10-01', '2025-10-31', 14000, 14000)], ['2025-10']));
        self::assertArrayNotHasKey('gap:2025-10', $this->plan('2025-11-09', [$this->review('2025-10-01', '2025-10-31', null, 14000)], ['2025-10']), 'sans solde saisi, pas d\'écart');
    }

    #[Test]
    public function testPendingRecurringDuesAreOneGroupedItem(): void
    {
        self::assertArrayHasKey('recurring', $this->plan('2025-10-10', [], [], [], 2));
        self::assertArrayNotHasKey('recurring', $this->plan('2025-10-10', [], [], [], 0));
    }

    #[Test]
    public function testTheYearIsToCloseThenLateAndClosingIsPreparedThirtyDaysBefore(): void
    {
        self::assertArrayNotHasKey('prepare-close', $this->plan('2026-08-30'));
        self::assertArrayHasKey('prepare-close', $this->plan('2026-08-31'));
        self::assertArrayHasKey('prepare-close', $this->plan('2026-09-01'));
        self::assertArrayNotHasKey('close', $this->plan('2026-09-30'));
        self::assertSame('a_faire', $this->plan('2026-10-02')['close'][0]);
        self::assertSame('en_retard', $this->plan('2026-11-05')['close'][0]);
    }

    #[Test]
    public function testAClosedYearAsksForTheReportAndTheApprovalAndAnApprovedOneAsksForNothing(): void
    {
        $closed = $this->plan('2026-12-01', [], [], [], 0, $this->year(FiscalYearStatus::Cloture));
        self::assertSame(['report', 'approve'], array_keys($closed));
        $approved = new FiscalYear(1, '2025-2026', new \DateTimeImmutable('2025-10-01'), new \DateTimeImmutable('2026-09-30'), FiscalYearStatus::Approuve, 10000, 9000, 1, new \DateTimeImmutable('2026-12-12'), 3);
        self::assertSame([], $this->plan('2026-12-20', [], [], [], 0, $approved));
    }

    #[Test]
    public function testLateItemsComeFirstThenByDueDate(): void
    {
        $items = (new TodoPlanner())->plan($this->year(), new \DateTimeImmutable('2025-12-06'), [], [], [], 0);

        self::assertSame(TodoState::EnRetard, $items[0]->state);
        $states = array_map(static fn ($i): string => $i->state->value, $items);
        $sorted = $states;
        usort($sorted, static fn (string $a, string $b): int => ($a === 'en_retard' ? 0 : 1) <=> ($b === 'en_retard' ? 0 : 1));
        self::assertSame($sorted, $states);
    }

    #[Test]
    public function testLabelsCarryNoAmountNorName(): void
    {
        $items = (new TodoPlanner())->plan($this->year(), new \DateTimeImmutable('2025-12-06'), [$this->review('2025-10-01', '2025-10-31', 15000, 14000)], [], [], 1);

        foreach ($items as $item) {
            self::assertDoesNotMatchRegularExpression('/\d+[,.]\d{2}|€/u', $item->label . $item->action);
            self::assertStringStartsWith('/admin/accounting', $item->url);
        }
    }
}
