<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Service;

use App\Accounting\Repository\MysqlEntryRepository;
use App\Accounting\Repository\MysqlFiscalYearRepository;
use App\Accounting\Repository\MysqlStatementRepository;
use App\Accounting\Repository\MysqlRecurringOccurrenceRepository;
use App\Accounting\Repository\MysqlRecurringTemplateRepository;
use App\Accounting\Service\Recurring\RecurrenceSchedule;
use App\Accounting\Service\Recurring\RecurringOccurrenceService;
use App\Accounting\Service\Recurring\RecurringTemplateService;
use App\Accounting\Service\Recurring\TemplateInputPolicy;
use App\Accounting\Service\StatementFileStore;
use App\Accounting\Service\StatementInputPolicy;
use App\Accounting\Service\StatementService;
use App\Accounting\Service\AccountingAmountParser;
use App\Accounting\Service\EntryInputPolicy;
use App\Accounting\Service\EntryService;
use App\Accounting\Service\FiscalYearInputPolicy;
use App\Accounting\Service\FiscalYearService;
use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Database\TransactionRunner;
use App\Security\AdminAccess;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Scenarios\MessagingScenario;
use Psr\Log\AbstractLogger;

/** Décor commun des tests de la comptabilité : un administrateur, un musicien, un administrateur désactivé et les deux services réels. */
abstract class AccountingTestCase extends RepositoryTestCase
{
    use MessagingScenario;

    protected FiscalYearService $years;
    protected EntryService $entries;
    protected MysqlFiscalYearRepository $yearRepository;
    protected MysqlEntryRepository $entryRepository;
    protected StatementService $statements;
    protected RecurringTemplateService $templates;
    protected RecurringOccurrenceService $occurrences;
    protected string $statementDir;
    protected User $admin;
    protected User $musician;
    protected User $gone;
    /** Horloge fixe des tests (le décor commun garde la sienne en privé). */
    protected \DateTimeImmutable $clock;
    /** @var list<string> messages et contextes écrits dans le journal */
    protected array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $this->clock = new \DateTimeImmutable('2026-10-04 12:00:00');
        $this->yearRepository = new MysqlFiscalYearRepository($this->pdo);
        $this->entryRepository = new MysqlEntryRepository($this->pdo);
        $this->buildServices($this->yearRepository, $this->entryRepository, $this->pdo);
        $templates = new MysqlRecurringTemplateRepository($this->pdo);
        $this->templates = new RecurringTemplateService(new AdminAccess(), new TemplateInputPolicy(new AccountingAmountParser()), $templates, $this->logger());
        $this->occurrences = new RecurringOccurrenceService(new AdminAccess(), $templates, new MysqlRecurringOccurrenceRepository($this->pdo), $this->yearRepository, $this->entries, new RecurrenceSchedule(), $this->logger());
        $this->statementDir = sys_get_temp_dir() . '/rb-acc-statements-' . bin2hex(random_bytes(4));
        $this->statements = new StatementService(new AdminAccess(), new StatementInputPolicy(new AccountingAmountParser()), $this->yearRepository, $this->entryRepository, new MysqlStatementRepository($this->pdo), new StatementFileStore($this->statementDir), new TransactionRunner($this->pdo), $this->logger());
        $this->admin = $this->users->save(new User(0, 'admin@rehearsalbox.test', 'hash', 'Admin', UserRole::Admin, true, 0, null));
        $this->gone = $this->users->save(new User(0, 'parti@rehearsalbox.test', 'hash', 'Parti', UserRole::Admin, false, 0, null));
        $this->musician = $this->user('Musicien');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->statementDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->statementDir);
        parent::tearDown();
    }

    private function logger(): AbstractLogger
    {
        return new class ($this->logged) extends AbstractLogger {
            /** @param list<string> $sink */
            public function __construct(private array &$sink)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->sink[] = $message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
            }
        };
    }

    protected function buildServices(MysqlFiscalYearRepository|\App\Accounting\Repository\FiscalYearRepositoryInterface $years, \App\Accounting\Repository\EntryRepositoryInterface $entries, \PDO $pdo): void
    {
        $logger = new class ($this->logged) extends AbstractLogger {
            /** @param list<string> $sink */
            public function __construct(private array &$sink)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->sink[] = $message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
            }
        };
        $transactions = new TransactionRunner($pdo);
        $amounts = new AccountingAmountParser();
        $this->years = new FiscalYearService(new AdminAccess(), new FiscalYearInputPolicy($amounts), $years, $entries, $transactions, $logger);
        $this->entries = new EntryService(new AdminAccess(), new EntryInputPolicy($amounts), $years, $entries, new \App\Accounting\Repository\MysqlMemberDuesRepository($pdo), $transactions, $logger);
    }

    /** Crée un exercice du 1er octobre 2025 au 30 septembre 2026 (ouvert, solde d'ouverture 100,00 €) et rend son identifiant. */
    protected function openYear(string $label = '2025-2026', string $start = '2025-10-01', string $end = '2026-09-30', string $opening = '100,00'): int
    {
        return $this->years->create($this->admin, ['label' => $label, 'startsOn' => $start, 'endsOn' => $end, 'openingBalance' => $opening], $this->clock);
    }

    /** @param array<string, mixed> $overrides */
    protected function record(int $yearId, array $overrides = []): \App\Accounting\Entity\AccountingEntry
    {
        return $this->entries->record($this->admin, $yearId, $overrides + [
            'occurredOn' => '2025-11-05',
            'label' => 'Cotisation',
            'amount' => '20,00',
            'direction' => 'recette',
            'category' => 'cotisations',
            'paymentMethod' => 'virement',
        ], $this->clock);
    }

    protected function expense(int $yearId, string $amount = '156,00'): \App\Accounting\Entity\AccountingEntry
    {
        return $this->record($yearId, ['label' => 'Loyer', 'amount' => $amount, 'direction' => 'depense', 'category' => 'loyer', 'voucherRef' => 'VIR-LOYER']);
    }
}
