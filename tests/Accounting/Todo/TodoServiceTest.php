<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Todo;

use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Exception\FiscalYearLockedException;
use App\Accounting\Exception\FiscalYearNotFoundException;
use App\Accounting\Repository\Month\MysqlMonthMarkRepository;
use App\Accounting\Todo\TodoPlanner;
use App\Accounting\Todo\TodoService;
use App\Security\AdminAccess;
use App\Security\Exception\AccessDeniedException;
use App\Tests\Accounting\Service\AccountingTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

/** #362 : « À faire » rassemblé depuis la base (relevés, écritures, marques, échéances) ; « rien à saisir ce mois-ci » ; droits. */
final class TodoServiceTest extends AccountingTestCase
{
    private TodoService $todo;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = new \DateTimeImmutable('2025-12-20');
        $this->todo = new TodoService(new AdminAccess(), new TodoPlanner(), $this->yearRepository, $this->entryRepository, new MysqlMonthMarkRepository($this->pdo), $this->statements, $this->occurrences, new NullLogger());
    }

    /** @return list<string> */
    private function keys(int $yearId): array
    {
        $year = $this->yearRepository->find($yearId) ?? throw new \LogicException();

        return array_map(static fn ($i): string => $i->key, $this->todo->forYear($this->admin, $year, $this->today));
    }

    #[Test]
    public function testAnEntryClearsTheMonthAndAnEmptyMarkClearsAnother(): void
    {
        $year = $this->openYear();
        $this->record($year, ['occurredOn' => '2025-10-10']);
        $this->todo->markEmptyMonth($this->admin, $year, ['month' => '2025-11'], $this->today, $this->clock);

        $keys = $this->keys($year);

        self::assertNotContains('entries:2025-10', $keys);
        self::assertNotContains('entries:2025-11', $keys);
        self::assertContains('statement:2025-10', $keys);
        self::assertContains('statement:2025-11', $keys);
        self::assertNotContains('entries:2025-12', $keys, 'décembre n\'est pas terminé');
    }

    #[Test]
    public function testAMarkIsIdempotentAndNeverWritesAnEntry(): void
    {
        $year = $this->openYear();

        $this->todo->markEmptyMonth($this->admin, $year, ['month' => '2025-11'], $this->today, $this->clock);
        $this->todo->markEmptyMonth($this->admin, $year, ['month' => '2025-11'], $this->today, $this->clock);

        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_month_marks')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_entries')->fetchColumn());
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function invalidMonths(): iterable
    {
        yield 'mois courant' => [['month' => '2025-12']];
        yield 'mois futur' => [['month' => '2026-03']];
        yield 'avant l\'exercice' => [['month' => '2025-09']];
        yield 'après l\'exercice' => [['month' => '2026-10']];
        yield 'avec un jour' => [['month' => '2025-11-01']];
        yield 'mois 13' => [['month' => '2025-13']];
        yield 'tableau' => [['month' => ['2025-11']]];
        yield 'absent' => [[]];
    }

    /** @param array<string, mixed> $input */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidMonths')]
    public function testAnInvalidMonthIsRefusedAndNothingIsStored(array $input): void
    {
        $year = $this->openYear();

        try {
            $this->todo->markEmptyMonth($this->admin, $year, $input, $this->today, $this->clock);
            self::fail('refus attendu');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey('month', $e->fields());
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_month_marks')->fetchColumn());
    }

    #[Test]
    public function testAClosedOrUnknownYearRefusesTheMark(): void
    {
        $year = $this->openYear();
        $this->years->close($this->admin, $year, 0, $this->clock);
        try {
            $this->todo->markEmptyMonth($this->admin, $year, ['month' => '2025-11'], $this->today, $this->clock);
            self::fail('exercice clos');
        } catch (FiscalYearLockedException) {
        }
        $this->expectException(FiscalYearNotFoundException::class);
        $this->todo->markEmptyMonth($this->admin, 99999999, ['month' => '2025-11'], $this->today, $this->clock);
    }

    #[Test]
    public function testOnlyAdministratorsReadOrMark(): void
    {
        $yearId = $this->openYear();
        $year = $this->yearRepository->find($yearId) ?? throw new \LogicException();
        $denied = 0;
        foreach ([$this->musician, $this->gone] as $actor) {
            foreach ([
                fn () => $this->todo->forYear($actor, $year, $this->today),
                fn () => $this->todo->markEmptyMonth($actor, $yearId, ['month' => '2025-11'], $this->today),
            ] as $call) {
                try {
                    $call();
                } catch (AccessDeniedException) {
                    ++$denied;
                }
            }
        }
        self::assertSame(4, $denied);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_month_marks')->fetchColumn());
    }

    #[Test]
    public function testRecurringDuesAndStatementGapsFeedTheList(): void
    {
        $year = $this->openYear();
        $this->templates->create($this->admin, ['label' => 'Loyer', 'direction' => 'depense', 'category' => 'loyer', 'paymentMethod' => 'virement', 'amount' => '498,00', 'voucherRef' => 'V', 'periodicity' => 'trimestrielle', 'dueDay' => '5', 'firstMonth' => '2025-10'], $this->clock);
        $upload = sys_get_temp_dir() . '/rb-todo-' . bin2hex(random_bytes(4));
        file_put_contents($upload, "%PDF-1.4\n1 0 obj\n<< >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
        $this->statements->deposit($this->admin, $year, ['periodStart' => '2025-10-01', 'periodEnd' => '2025-10-31', 'closingBalance' => '999,00'], $upload, 'r.pdf', (int) filesize($upload), $this->clock);
        unlink($upload);

        $keys = $this->keys($year);

        self::assertContains('recurring', $keys);
        self::assertContains('gap:2025-10', $keys);
        self::assertNotContains('statement:2025-10', $keys);
    }
}
