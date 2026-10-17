<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Service;

use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Exception\FiscalYearLockedException;
use App\Accounting\Exception\FiscalYearNotFoundException;
use App\Accounting\Entity\FiscalYear;
use App\Accounting\Repository\FiscalYearRepositoryInterface;
use App\Security\Exception\AccessDeniedException;
use PHPUnit\Framework\Attributes\Test;

/** #362 : écritures en lot (validation de l'import CSV) : tout ou rien, numéros sans trou, saisies refusées rapportées ensemble, rappel dans la transaction. */
final class EntryBatchTest extends AccountingTestCase
{
    /** @param array<string, mixed> $override */
    private function input(array $override = []): array
    {
        return $override + ['occurredOn' => '2025-11-05', 'label' => 'Ligne', 'amount' => '10,00', 'direction' => 'recette', 'category' => 'autres_recettes', 'paymentMethod' => 'virement'];
    }

    private function rows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_entries')->fetchColumn();
    }

    #[Test]
    public function testAllEntriesAreWrittenWithConsecutiveNumbersAndKeepTheirKeys(): void
    {
        $year = $this->openYear();

        $entries = $this->entries->recordBatch($this->admin, [
            'a' => ['year' => $year, 'input' => $this->input(['label' => 'Une'])],
            'b' => ['year' => $year, 'input' => $this->input(['label' => 'Deux', 'amount' => '5,50'])],
            'c' => ['year' => $year, 'input' => $this->input(['label' => 'Trois'])],
        ], $this->clock);

        self::assertSame(['a', 'b', 'c'], array_keys($entries));
        self::assertSame([1, 2, 3], array_map(static fn ($e): int => $e->number, array_values($entries)));
        self::assertSame(550, $entries['b']->amountCents);
        self::assertSame(4, $this->yearRepository->find($year)?->nextEntryNumber);
    }

    #[Test]
    public function testOneInvalidInputWritesNothingAndEveryErrorIsReportedWithItsKey(): void
    {
        $year = $this->openYear();

        try {
            $this->entries->recordBatch($this->admin, [
                'ok' => ['year' => $year, 'input' => $this->input()],
                '7' => ['year' => $year, 'input' => $this->input(['amount' => 'abc'])],
                '9' => ['year' => $year, 'input' => $this->input(['label' => ''])],
            ], $this->clock);
            self::fail('refus attendu');
        } catch (AccountingValidationException $e) {
            self::assertEqualsCanonicalizing(['7.amount', '9.label'], array_keys($e->fields()));
        }
        self::assertSame(0, $this->rows());
        self::assertSame(1, $this->yearRepository->find($year)?->nextEntryNumber);
    }

    #[Test]
    public function testAFailureInTheMiddleRollsBackTheEarlierEntriesAndTheirNumbers(): void
    {
        $year = $this->openYear();

        try {
            $this->entries->recordBatch($this->admin, [
                'a' => ['year' => $year, 'input' => $this->input()],
                'b' => ['year' => $year, 'input' => $this->input(['occurredOn' => '2030-01-01'])],
            ], $this->clock);
            self::fail('refus attendu');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey('b.occurredOn', $e->fields());
        }
        self::assertSame(0, $this->rows());
        self::assertSame(1, $this->yearRepository->find($year)?->nextEntryNumber);
    }

    #[Test]
    public function testTheAfterCallbackRunsInTheTransactionAndItsFailureCancelsEverything(): void
    {
        $year = $this->openYear();
        $seen = [];

        try {
            $this->entries->recordBatch($this->admin, [
                'a' => ['year' => $year, 'input' => $this->input(), 'after' => function ($entry) use (&$seen): void {
                    $seen[] = $entry->number;
                }],
                'b' => ['year' => $year, 'input' => $this->input(), 'after' => static function (): void {
                    throw new \RuntimeException('panne');
                }],
            ], $this->clock);
            self::fail('panne attendue');
        } catch (\RuntimeException) {
        }
        self::assertSame([1], $seen);
        self::assertSame(0, $this->rows(), 'la panne du second annule le premier');
    }

    #[Test]
    public function testTheSameMemberAndMonthTwiceInOneBatchIsRefused(): void
    {
        $year = $this->openYear();
        $dues = $this->input(['category' => 'cotisations', 'memberUserId' => (string) $this->musician->id(), 'periodMonth' => '2025-11']);

        try {
            $this->entries->recordBatch($this->admin, ['a' => ['year' => $year, 'input' => $dues], 'b' => ['year' => $year, 'input' => $dues]], $this->clock);
            self::fail('refus attendu');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey('b.periodMonth', $e->fields());
        }
        self::assertSame(0, $this->rows());
    }

    #[Test]
    public function testTwoYearsAreLockedInOrderAndNumberedIndependently(): void
    {
        $first = $this->openYear('2024-2025', '2024-10-01', '2025-09-30');
        $this->years->close($this->admin, $first, 0, $this->clock);
        $second = $this->openYear('2025-2026', '2025-10-01', '2026-09-30', '');
        $this->pdo->exec("UPDATE accounting_fiscal_years SET status = 'ouvert' WHERE id = {$first}");

        $entries = $this->entries->recordBatch($this->admin, [
            'late' => ['year' => $second, 'input' => $this->input(['occurredOn' => '2025-11-05'])],
            'early' => ['year' => $first, 'input' => $this->input(['occurredOn' => '2025-02-05'])],
            'late2' => ['year' => $second, 'input' => $this->input(['occurredOn' => '2025-11-06'])],
        ], $this->clock);

        self::assertSame([1, 1, 2], [$entries['late']->number, $entries['early']->number, $entries['late2']->number]);
    }

    #[Test]
    public function testAClosedOrUnknownYearAndNonAdministratorsAreRefused(): void
    {
        $year = $this->openYear();
        $this->years->close($this->admin, $year, 0, $this->clock);
        try {
            $this->entries->recordBatch($this->admin, ['a' => ['year' => $year, 'input' => $this->input()]], $this->clock);
            self::fail('exercice clos');
        } catch (FiscalYearLockedException) {
        }
        try {
            $this->entries->recordBatch($this->admin, ['a' => ['year' => 99999999, 'input' => $this->input()]], $this->clock);
            self::fail('exercice inconnu');
        } catch (FiscalYearNotFoundException) {
        }
        $denied = 0;
        foreach ([$this->musician, $this->gone] as $actor) {
            try {
                $this->entries->recordBatch($actor, ['a' => ['year' => $year, 'input' => $this->input()]]);
            } catch (AccessDeniedException) {
                ++$denied;
            }
        }
        self::assertSame(2, $denied);
        self::assertSame(0, $this->rows());
    }

    #[Test]
    public function testYearsAreLockedInAscendingOrderWhateverTheOrderOfTheItems(): void
    {
        $first = $this->openYear('2024-2025', '2024-10-01', '2025-09-30');
        $this->years->close($this->admin, $first, 0, $this->clock);
        $second = $this->openYear('2025-2026', '2025-10-01', '2026-09-30', '');
        $this->pdo->exec("UPDATE accounting_fiscal_years SET status = 'ouvert' WHERE id = {$first}");
        $locks = [];
        $recording = new class ($this->yearRepository, $locks) implements FiscalYearRepositoryInterface {
            /** @param list<int> $locks */
            public function __construct(private readonly FiscalYearRepositoryInterface $inner, private array &$locks)
            {
            }

            public function insert(string $label, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn, int $openingBalanceCents, int $actorId, \DateTimeImmutable $now): int
            {
                return $this->inner->insert($label, $startsOn, $endsOn, $openingBalanceCents, $actorId, $now);
            }

            public function find(int $id): ?FiscalYear
            {
                return $this->inner->find($id);
            }

            public function lock(int $id): ?FiscalYear
            {
                $this->locks[] = $id;

                return $this->inner->lock($id);
            }

            public function lockAll(): void
            {
                $this->inner->lockAll();
            }

            public function listAll(): array
            {
                return $this->inner->listAll();
            }

            public function latest(): ?FiscalYear
            {
                return $this->inner->latest();
            }

            public function findOpenContaining(\DateTimeImmutable $date): ?FiscalYear
            {
                return $this->inner->findOpenContaining($date);
            }

            public function incrementNextNumber(int $id): bool
            {
                return $this->inner->incrementNextNumber($id);
            }

            public function close(int $id, int $expectedRevision, int $closingBalanceCents, int $actorId, \DateTimeImmutable $now): bool
            {
                return $this->inner->close($id, $expectedRevision, $closingBalanceCents, $actorId, $now);
            }

            public function approve(int $id, \DateTimeImmutable $approvedOn, int $actorId, \DateTimeImmutable $now): bool
            {
                return $this->inner->approve($id, $approvedOn, $actorId, $now);
            }
        };
        $this->buildServices($recording, $this->entryRepository, $this->pdo);

        $this->entries->recordBatch($this->admin, [
            'late' => ['year' => $second, 'input' => $this->input(['occurredOn' => '2025-11-05'])],
            'early' => ['year' => $first, 'input' => $this->input(['occurredOn' => '2025-02-05'])],
        ], $this->clock);

        self::assertSame([$first, $second], $locks, 'toujours du plus petit identifiant au plus grand : jamais d\'interblocage entre deux lots');
    }
}
