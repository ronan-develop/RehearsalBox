<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Service\Recurring;

use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Exception\RecurringTemplateNotFoundException;
use App\Security\Exception\AccessDeniedException;
use App\Tests\Accounting\Service\AccountingTestCase;
use PHPUnit\Framework\Attributes\Test;

/** #362 : modèles récurrents et leurs échéances : droits, liste « À confirmer », confirmation, abandon, atomicité, double clic. */
final class RecurringServicesTest extends AccountingTestCase
{
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = new \DateTimeImmutable('2026-01-20');
    }

    /** @param array<string, mixed> $override */
    private function template(array $override = []): int
    {
        return $this->templates->create($this->admin, $override + ['label' => 'Loyer du local', 'direction' => 'depense', 'category' => 'loyer', 'paymentMethod' => 'virement', 'amount' => '498,00', 'voucherRef' => 'Virement loyer', 'periodicity' => 'trimestrielle', 'dueDay' => '5', 'firstMonth' => '2025-10'], $this->clock);
    }

    private function year(): \App\Accounting\Entity\FiscalYear
    {
        return $this->yearRepository->find($this->openYear()) ?? throw new \LogicException();
    }

    private function entryCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_entries')->fetchColumn();
    }

    #[Test]
    public function testOnlyAdministratorsManageTemplatesAndTheirOccurrences(): void
    {
        $id = $this->template();
        $year = $this->year();
        $denied = 0;
        foreach ([$this->musician, $this->gone] as $actor) {
            foreach ([
                fn () => $this->templates->create($actor, []),
                fn () => $this->templates->update($actor, $id, []),
                fn () => $this->templates->list($actor),
                fn () => $this->occurrences->pending($actor, $year, $this->today),
                fn () => $this->occurrences->confirm($actor, $id, ['period' => '2025-10-01'], $this->today),
                fn () => $this->occurrences->ignore($actor, $id, ['period' => '2025-10-01'], $this->today),
            ] as $call) {
                try {
                    $call();
                } catch (AccessDeniedException) {
                    ++$denied;
                }
            }
        }
        self::assertSame(12, $denied);
        self::assertSame(0, $this->entryCount());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_recurring_occurrences')->fetchColumn());
    }

    #[Test]
    public function testPendingListsReachedUndecidedDuesOfActiveTemplatesOnly(): void
    {
        $this->template();
        $this->template(['label' => 'Ancien', 'isActive' => false]);
        $year = $this->year();

        $pending = $this->occurrences->pending($this->admin, $year, $this->today);

        self::assertSame(['2025-10-05', '2026-01-05'], array_map(static fn ($p) => $p->dueOn->format('Y-m-d'), $pending), 'avril et juillet ne sont pas atteints');
        self::assertSame('Loyer du local', $pending[0]->template->label);
        self::assertSame(0, $this->entryCount(), 'lister ne crée jamais une écriture');
    }

    #[Test]
    public function testConfirmingCreatesTheEntryThroughTheLedgerRulesAndRemovesTheDueFromTheList(): void
    {
        $id = $this->template();
        $year = $this->year();

        $entry = $this->occurrences->confirm($this->admin, $id, ['period' => '2025-10-01'], $this->today, $this->clock);

        self::assertSame(1, $entry->number);
        self::assertSame(49800, $entry->amountCents);
        self::assertSame('2025-10-05', $entry->occurredOn->format('Y-m-d'));
        self::assertSame('Loyer du local', $entry->label);
        self::assertSame(['2026-01-05'], array_map(static fn ($p) => $p->dueOn->format('Y-m-d'), $this->occurrences->pending($this->admin, $year, $this->today)));
        self::assertSame($entry->id, (int) $this->pdo->query('SELECT entry_id FROM accounting_recurring_occurrences')->fetchColumn());
    }

    #[Test]
    public function testAVariableAmountMustBeTypedAtEachDueAndAFixedOneCanBeOverridden(): void
    {
        $electricity = $this->template(['label' => 'EDF', 'category' => 'electricite', 'amount' => '', 'periodicity' => 'mensuelle']);
        $this->year();

        try {
            $this->occurrences->confirm($this->admin, $electricity, ['period' => '2025-10-01'], $this->today, $this->clock);
            self::fail('montant attendu');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey('amount', $e->fields());
        }
        self::assertSame(0, $this->entryCount());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_recurring_occurrences')->fetchColumn(), 'rien n\'est décidé si l\'écriture échoue');

        $entry = $this->occurrences->confirm($this->admin, $electricity, ['period' => '2025-10-01', 'amount' => '63,40', 'voucherRef' => 'Facture EDF 10'], $this->today, $this->clock);
        self::assertSame(6340, $entry->amountCents);
        $rent = $this->template();
        self::assertSame(51000, $this->occurrences->confirm($this->admin, $rent, ['period' => '2025-10-01', 'amount' => '510'], $this->today, $this->clock)->amountCents);
    }

    #[Test]
    public function testAnOccurrenceIsDecidedOnlyOnceWhateverTheWay(): void
    {
        $id = $this->template();
        $this->year();
        $this->occurrences->confirm($this->admin, $id, ['period' => '2025-10-01'], $this->today, $this->clock);

        foreach ([
            fn () => $this->occurrences->confirm($this->admin, $id, ['period' => '2025-10-01'], $this->today, $this->clock),
            fn () => $this->occurrences->ignore($this->admin, $id, ['period' => '2025-10-01'], $this->today, $this->clock),
        ] as $second) {
            try {
                $second();
                self::fail('déjà traitée');
            } catch (AccountingValidationException $e) {
                self::assertArrayHasKey('period', $e->fields());
            }
        }
        self::assertSame(1, $this->entryCount(), 'le double clic ne crée pas une deuxième écriture (et son numéro est rendu)');
        self::assertSame(2, $this->yearRepository->find((int) $this->pdo->query('SELECT fiscal_year_id FROM accounting_entries')->fetchColumn())?->nextEntryNumber);
    }

    #[Test]
    public function testIgnoringRecordsTheDecisionWithoutAnyEntry(): void
    {
        $id = $this->template();
        $year = $this->year();

        $this->occurrences->ignore($this->admin, $id, ['period' => '2025-10-01'], $this->today, $this->clock);

        self::assertSame(0, $this->entryCount());
        self::assertSame(['2026-01-05'], array_map(static fn ($p) => $p->dueOn->format('Y-m-d'), $this->occurrences->pending($this->admin, $year, $this->today)));
    }

    #[Test]
    public function testAFutureOffRhythmOrMalformedDueIsRefused(): void
    {
        $id = $this->template();
        $this->year();
        foreach (['2026-04-01', '2025-11-01', '2025-07-01', '2025-10-05', 'hier', '', '2025-13-01'] as $period) {
            try {
                $this->occurrences->confirm($this->admin, $id, ['period' => $period], $this->today, $this->clock);
                self::fail("refus attendu pour {$period}");
            } catch (AccountingValidationException $e) {
                self::assertArrayHasKey('period', $e->fields(), $period);
            }
        }
        self::assertSame(0, $this->entryCount());
    }

    #[Test]
    public function testAnInactiveOrUnknownTemplateCannotBeConfirmed(): void
    {
        $inactive = $this->template(['isActive' => false]);
        $this->year();
        try {
            $this->occurrences->confirm($this->admin, $inactive, ['period' => '2025-10-01'], $this->today, $this->clock);
            self::fail('refus attendu');
        } catch (AccountingValidationException) {
        }
        $this->expectException(RecurringTemplateNotFoundException::class);
        $this->occurrences->confirm($this->admin, 99999999, ['period' => '2025-10-01'], $this->today, $this->clock);
    }

    #[Test]
    public function testTheEntryDateMustFallInAnOpenYearAndAClosedYearHasNoPendingList(): void
    {
        $id = $this->template();
        $year = $this->year();
        try {
            $this->occurrences->confirm($this->admin, $id, ['period' => '2025-10-01', 'occurredOn' => '2024-01-01'], $this->today, $this->clock);
            self::fail('refus attendu');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey('occurredOn', $e->fields());
        }
        self::assertSame(0, $this->entryCount());
        $this->years->close($this->admin, $year->id, 0, $this->clock);
        $closed = $this->yearRepository->find($year->id) ?? throw new \LogicException();
        self::assertSame([], $this->occurrences->pending($this->admin, $closed, $this->today));
    }

    #[Test]
    public function testEditingATemplateNeverRewritesWhatWasAlreadyDecided(): void
    {
        $id = $this->template();
        $this->year();
        $entry = $this->occurrences->confirm($this->admin, $id, ['period' => '2025-10-01'], $this->today, $this->clock);

        $this->templates->update($this->admin, $id, ['label' => 'Loyer révisé', 'direction' => 'depense', 'category' => 'loyer', 'paymentMethod' => 'virement', 'amount' => '510,00', 'voucherRef' => 'V', 'periodicity' => 'trimestrielle', 'dueDay' => '5', 'firstMonth' => '2025-10']);

        self::assertSame(49800, $this->entryRepository->find($entry->id)?->amountCents);
        self::assertSame('Loyer du local', $this->entryRepository->find($entry->id)?->label);
        $this->expectException(RecurringTemplateNotFoundException::class);
        $this->templates->update($this->admin, 99999999, ['label' => 'x', 'direction' => 'depense', 'category' => 'loyer', 'paymentMethod' => 'virement', 'periodicity' => 'mensuelle', 'dueDay' => '5', 'firstMonth' => '2025-10']);
    }

    #[Test]
    public function testTheLogReceivesIdentifiersNeverLabelsOrAmounts(): void
    {
        $id = $this->template(['label' => 'Loyer secret-FR76']);
        $this->year();
        $this->occurrences->confirm($this->admin, $id, ['period' => '2025-10-01'], $this->today, $this->clock);

        $log = implode("\n", $this->logged);
        self::assertStringContainsString('échéance confirmée', $log);
        self::assertStringNotContainsString('FR76', $log);
        self::assertStringNotContainsString('49800', $log);
    }
}
