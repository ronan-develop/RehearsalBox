<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Service;

use App\Accounting\Entity\Direction;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Exception\EntryNotFoundException;
use App\Accounting\Exception\FiscalYearLockedException;
use App\Accounting\Exception\FiscalYearNotFoundException;
use App\Security\Exception\AccessDeniedException;
use PHPUnit\Framework\Attributes\Test;

/** #362 : saisie d'une écriture, numérotation sans trou, gel d'un exercice clos, droits. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class EntryServiceTest extends AccountingTestCase
{
    #[Test]
    public function testOnlyActiveAdministratorsCanRecordReverseOrList(): void
    {
        $id = $this->openYear();
        $entry = $this->record($id);
        $calls = [
            fn ($u) => $this->entries->record($u, $id, ['label' => 'x']),
            fn ($u) => $this->entries->reverse($u, $entry->id, ['reason' => 'x', 'occurredOn' => '2025-11-06']),
            fn ($u) => $this->entries->list($u, $id),
        ];

        foreach ([$this->musician, $this->gone] as $actor) {
            foreach ($calls as $call) {
                try {
                    $call($actor);
                    self::fail('Accès refusé attendu');
                } catch (AccessDeniedException) {
                    self::addToAssertionCount(1);
                }
            }
        }
        self::assertCount(1, $this->entries->list($this->admin, $id));
    }

    #[Test]
    public function testEntriesAreNumberedWithoutGapAndStoredExactly(): void
    {
        $id = $this->openYear();

        $first = $this->record($id);
        $second = $this->expense($id, '468,00');
        $third = $this->record($id, ['amount' => '0,01', 'note' => 'un centime']);

        self::assertSame([1, 2, 3], [$first->number, $second->number, $third->number]);
        self::assertSame(2000, $first->amountCents);
        self::assertSame(46800, $second->amountCents);
        self::assertSame(Direction::Depense, $second->direction);
        self::assertSame('VIR-LOYER', $second->voucherRef);
        self::assertSame(1, $third->amountCents);
        self::assertSame(4, $this->years->get($this->admin, $id)->nextEntryNumber);
        self::assertSame([1, 2, 3], array_map(static fn ($e) => $e->number, $this->entries->list($this->admin, $id)));
    }

    #[Test]
    public function testARefusedEntryNeverConsumesANumber(): void
    {
        $id = $this->openYear();

        foreach ([['amount' => 'abc'], ['occurredOn' => '2027-01-01'], ['occurredOn' => '2025-09-30'], ['category' => 'loyer']] as $bad) {
            try {
                $this->record($id, $bad);
                self::fail('Écriture refusée attendue');
            } catch (AccountingValidationException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame(1, $this->record($id)->number, 'le premier numéro n\'a pas été brûlé');
    }

    #[Test]
    public function testDatesOutsideTheYearAreRefusedOnTheDateField(): void
    {
        $id = $this->openYear();

        foreach (['2025-09-30', '2026-10-01'] as $outside) {
            try {
                $this->record($id, ['occurredOn' => $outside]);
                self::fail('Date hors exercice refusée attendue');
            } catch (AccountingValidationException $e) {
                self::assertArrayHasKey('occurredOn', $e->fields());
            }
        }
        self::assertSame(1, $this->record($id, ['occurredOn' => '2025-10-01'])->number, 'le premier jour est dans l\'exercice');
        self::assertSame(2, $this->record($id, ['occurredOn' => '2026-09-30'])->number, 'le dernier jour aussi');
    }

    #[Test]
    public function testAClosedOrApprovedYearAcceptsNothingAndUnknownOnesAreNotFound(): void
    {
        $id = $this->openYear();
        $this->record($id);
        $this->years->close($this->admin, $id, 0, $this->clock);

        try {
            $this->record($id);
            self::fail('Un exercice clos ne reçoit plus d\'écriture');
        } catch (FiscalYearLockedException) {
            self::assertCount(1, $this->entries->list($this->admin, $id));
        }
        $this->years->approve($this->admin, $id, ['approvedOn' => '2026-10-04'], $this->clock);
        try {
            $this->record($id);
            self::fail('Un exercice approuvé ne reçoit plus d\'écriture');
        } catch (FiscalYearLockedException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(FiscalYearNotFoundException::class);
        $this->record(999999);
    }

    #[Test]
    public function testAFailureWhileNumberingRollsTheEntryBack(): void
    {
        $id = $this->openYear();
        $failing = new class ($this->pdo) extends \App\Accounting\Repository\MysqlFiscalYearRepository {
            public function incrementNextNumber(int $id): bool
            {
                throw new \RuntimeException('panne simulée');
            }
        };
        $this->buildServices($failing, $this->entryRepository, $this->pdo);

        try {
            $this->record($id);
            self::fail('La panne doit remonter');
        } catch (\RuntimeException) {
            self::assertSame([], $this->entries->list($this->admin, $id), 'aucune écriture orpheline');
            self::assertSame(1, $this->years->get($this->admin, $id)->nextEntryNumber);
        }
    }

    #[Test]
    public function testTwoServiceInstancesOnSeparateConnectionsStillNumberWithoutGapOrDuplicate(): void
    {
        $id = $this->openYear();
        $other = \App\Tests\Database\TestDatabase::connection();
        $first = $this->entries;
        $this->buildServices(new \App\Accounting\Repository\MysqlFiscalYearRepository($other), new \App\Accounting\Repository\MysqlEntryRepository($other), $other);
        $second = $this->entries;

        $numbers = [];
        for ($i = 0; $i < 6; ++$i) {
            $service = $i % 2 === 0 ? $first : $second;
            $numbers[] = $service->record($this->admin, $id, ['occurredOn' => '2025-11-05', 'label' => 'x', 'amount' => '1', 'direction' => 'recette', 'category' => 'cotisations', 'paymentMethod' => 'virement'], $this->clock)->number;
        }

        self::assertSame([1, 2, 3, 4, 5, 6], $numbers);
    }

    #[Test]
    public function testTheYearRowIsLockedForTheWholeWriteSoNoOtherConnectionCanInterleave(): void
    {
        $id = $this->openYear();
        $other = \App\Tests\Database\TestDatabase::connection();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $this->pdo->beginTransaction();
        try {
            $this->yearRepository->lock($id);
            try {
                $other->prepare('SELECT id FROM accounting_fiscal_years WHERE id = :id FOR UPDATE')->execute(['id' => $id]);
                self::fail('Une seconde écriture devrait attendre le verrou de l\'exercice');
            } catch (\PDOException $e) {
                self::assertStringContainsString('Lock wait timeout', $e->getMessage());
            }
        } finally {
            $this->pdo->rollBack();
        }
    }

    #[Test]
    public function testTheDatabaseItselfRefusesADuplicateNumberOrANonPositiveAmount(): void
    {
        $id = $this->openYear();
        $entry = $this->record($id);
        $insert = $this->pdo->prepare(
            "INSERT INTO accounting_entries (fiscal_year_id, entry_number, occurred_on, label, amount_cents, direction, category, payment_method, created_by_user_id, created_at)
             VALUES (:year, :number, '2025-11-05', 'x', :amount, 'recette', 'cotisations', 'virement', :user, '2026-10-04 12:00:00')"
        );

        foreach ([[$entry->number, 100], [99, 0]] as [$number, $amount]) {
            try {
                $insert->execute(['year' => $id, 'number' => $number, 'amount' => $amount, 'user' => $this->admin->id()]);
                self::fail('La base devait refuser');
            } catch (\PDOException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testAnEntryIsNeverEditedNorDeletedByAnyRepositoryMethod(): void
    {
        foreach ([\App\Accounting\Repository\EntryRepositoryInterface::class, \App\Accounting\Repository\MysqlEntryRepository::class] as $class) {
            $methods = array_map(static fn (\ReflectionMethod $m) => $m->getName(), (new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC));
            foreach ($methods as $name) {
                self::assertDoesNotMatchRegularExpression('/^(update|delete|remove|save|set|edit|modify|truncate|purge)/i', $name, "{$class}::{$name} permettrait de réécrire le passé");
            }
        }
        $sql = (string) file_get_contents(__DIR__ . '/../../../src/Accounting/Repository/MysqlEntryRepository.php');
        self::assertDoesNotMatchRegularExpression('/\b(UPDATE|DELETE)\b\s+(FROM\s+)?accounting_entries/i', $sql);
    }

    #[Test]
    public function testEntriesOfAnotherYearAreNotListedAndUnknownEntriesAreNotFound(): void
    {
        $first = $this->openYear();
        $this->record($first);
        $this->years->close($this->admin, $first, 0, $this->clock);
        $second = $this->openYear('2026-2027', '2026-10-01', '2027-09-30');

        self::assertSame([], $this->entries->list($this->admin, $second));
        $this->expectException(EntryNotFoundException::class);
        $this->entries->reverse($this->admin, 999999, ['reason' => 'x', 'occurredOn' => '2026-10-05'], $this->clock);
    }
}
