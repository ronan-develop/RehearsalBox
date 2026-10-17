<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Import\Validate;

use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Import\ImportService;
use App\Accounting\Import\Parse\CsvImportParser;
use App\Accounting\Import\Parse\ImportProfilePolicy;
use App\Accounting\Import\Validate\BatchValidationService;
use App\Accounting\Repository\Import\MysqlImportRepository;
use App\Accounting\Repository\Import\MysqlLabelRuleRepository;
use App\Accounting\Repository\MysqlRecurringOccurrenceRepository;
use App\Accounting\Repository\MysqlRecurringTemplateRepository;
use App\Accounting\Service\Recurring\RecurrenceSchedule;
use App\Database\TransactionRunner;
use App\Security\AdminAccess;
use App\Security\Exception\AccessDeniedException;
use App\Tests\Accounting\Service\AccountingTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

/** #362 livraison 7 : validation en lot : tout ou rien par le service d'écriture, valeurs de la ligne jamais du client, échéance confirmée, règle retenue, double traitement refusé. */
final class BatchValidationServiceTest extends AccountingTestCase
{
    private ImportService $import;
    private BatchValidationService $validation;
    private MysqlImportRepository $imports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->imports = new MysqlImportRepository($this->pdo);
        $this->import = new ImportService(new AdminAccess(), new ImportProfilePolicy(), new CsvImportParser(), $this->imports, new TransactionRunner($this->pdo), new NullLogger());
        $this->validation = new BatchValidationService(
            new AdminAccess(),
            $this->imports,
            $this->yearRepository,
            $this->entries,
            new MysqlLabelRuleRepository($this->pdo),
            new MysqlRecurringTemplateRepository($this->pdo),
            new MysqlRecurringOccurrenceRepository($this->pdo),
            new RecurrenceSchedule(),
            new NullLogger(),
        );
        $this->openYear();
    }

    /** @return array<string, mixed> */
    private function profile(): array
    {
        return ['delimiter' => 'semicolon', 'encoding' => 'UTF-8', 'skipRows' => '1', 'dateColumn' => '1', 'labelColumn' => '2', 'amountMode' => 'signed', 'amountColumn' => '3', 'decimalSeparator' => ',', 'dateFormat' => 'd/m/Y'];
    }

    /** @return array<string, int> libellé => identifiant de ligne */
    private function stage(string ...$rows): array
    {
        $this->import->stage($this->admin, "Date;Libellé;Montant\n" . implode("\n", $rows) . "\n", $this->profile(), $this->clock);
        $ids = [];
        foreach ($this->import->pending($this->admin) as $line) {
            $ids[$line->label] = $line->id;
        }

        return $ids;
    }

    /** @param array<string, mixed> $override */
    private function row(int $line, array $override = []): array
    {
        return $override + ['line' => $line, 'category' => 'cotisations', 'paymentMethod' => 'virement'];
    }

    private function rows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    #[Test]
    public function testCheckedLinesBecomeEntriesWithTheLineDateAmountAndDirectionAndLeaveTheWaitingRoom(): void
    {
        $ids = $this->stage('05/11/2025;VIR DON;12,50', '06/11/2025;ACHAT MICRO;-80,00');

        $result = $this->validation->validate($this->admin, [
            $this->row($ids['VIR DON'], ['category' => 'autres_recettes', 'label' => 'Don d\'un sympathisant']),
            $this->row($ids['ACHAT MICRO'], ['category' => 'materiel', 'paymentMethod' => 'carte', 'voucherRef' => 'Ticket 12']),
        ], $this->clock);

        self::assertSame(['entries' => 2, 'rules' => 0], $result);
        $rows = $this->pdo->query('SELECT entry_number, occurred_on, label, amount_cents, direction, category, payment_method FROM accounting_entries ORDER BY entry_number')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([1, '2025-11-05', 'Don d\'un sympathisant', 1250, 'recette', 'autres_recettes', 'virement'], array_values($rows[0]));
        self::assertSame([2, '2025-11-06', 'ACHAT MICRO', 8000, 'depense', 'materiel', 'carte'], array_values($rows[1]));
        self::assertSame([], $this->import->pending($this->admin));
        self::assertSame(2, $this->rows('accounting_entries'));
    }

    #[Test]
    public function testTheClientCannotChangeTheAmountTheDateOrTheDirectionOfALine(): void
    {
        $ids = $this->stage('05/11/2025;VIR DON;12,50');

        $this->validation->validate($this->admin, [$this->row($ids['VIR DON'], ['category' => 'autres_recettes', 'amount' => '9999,00', 'occurredOn' => '2026-01-01', 'direction' => 'depense', 'createdBy' => 777, 'entryNumber' => 99])], $this->clock);

        $entry = $this->pdo->query('SELECT occurred_on, amount_cents, direction, entry_number FROM accounting_entries')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['2025-11-05', 1250, 'recette', 1], array_values($entry));
    }

    #[Test]
    public function testOneRefusedRowCancelsEveryOtherAndAllErrorsAreKeyedByLine(): void
    {
        $ids = $this->stage('05/11/2025;BON;10,00', '06/11/2025;SANS PIECE;-20,00', '07/11/2025;MAUVAISE CATEGORIE;5,00');

        try {
            $this->validation->validate($this->admin, [
                $this->row($ids['BON'], ['category' => 'autres_recettes']),
                $this->row($ids['SANS PIECE'], ['category' => 'materiel']),
                $this->row($ids['MAUVAISE CATEGORIE'], ['category' => 'loyer']),
            ], $this->clock);
            self::fail('refus attendu');
        } catch (AccountingValidationException $e) {
            self::assertEqualsCanonicalizing([$ids['SANS PIECE'] . '.voucherRef', $ids['MAUVAISE CATEGORIE'] . '.category'], array_keys($e->fields()));
        }
        self::assertSame(0, $this->rows('accounting_entries'), 'tout ou rien');
        self::assertCount(3, $this->import->pending($this->admin), 'les lignes restent en attente');
        self::assertSame(1, $this->yearRepository->find((int) $this->pdo->query('SELECT id FROM accounting_fiscal_years')->fetchColumn())?->nextEntryNumber);
    }

    #[Test]
    public function testALineAlreadyValidatedOrDiscardedOrUnknownIsRefused(): void
    {
        $ids = $this->stage('05/11/2025;A;10,00', '06/11/2025;B;10,00');
        $this->validation->validate($this->admin, [$this->row($ids['A'], ['category' => 'autres_recettes'])], $this->clock);
        $this->import->discard($this->admin, $ids['B'], $this->clock);

        foreach ([$ids['A'], $ids['B'], 99999999] as $id) {
            try {
                $this->validation->validate($this->admin, [$this->row($id, ['category' => 'autres_recettes'])], $this->clock);
                self::fail("refus attendu pour {$id}");
            } catch (AccountingValidationException $e) {
                self::assertArrayHasKey("{$id}.line", $e->fields());
            }
        }
        self::assertSame(1, $this->rows('accounting_entries'), 'jamais deux écritures pour une ligne');
    }

    #[Test]
    public function testAMalformedRowsListIsRefused(): void
    {
        $ids = $this->stage('05/11/2025;A;10,00');
        foreach ([[], ['texte'], [['line' => 'abc']], [['line' => '0']], [$this->row($ids['A']), $this->row($ids['A'])]] as $rows) {
            try {
                $this->validation->validate($this->admin, $rows, $this->clock);
                self::fail('refus attendu');
            } catch (AccountingValidationException $e) {
                self::assertNotSame([], $e->fields());
            }
        }
        self::assertSame(0, $this->rows('accounting_entries'));
        $tooMany = array_fill(0, BatchValidationService::MAX_ROWS + 1, $this->row($ids['A']));
        $this->expectException(AccountingValidationException::class);
        $this->validation->validate($this->admin, $tooMany, $this->clock);
    }

    #[Test]
    public function testALineOutsideAnyOpenYearIsRefused(): void
    {
        $ids = $this->stage('05/11/2027;HORS EXERCICE;10,00');

        try {
            $this->validation->validate($this->admin, [$this->row($ids['HORS EXERCICE'], ['category' => 'autres_recettes'])], $this->clock);
            self::fail('refus attendu');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey($ids['HORS EXERCICE'] . '.line', $e->fields());
        }
    }

    #[Test]
    public function testRememberingCreatesAnExplicitRuleAndAsksForAUsablePattern(): void
    {
        $ids = $this->stage('05/11/2025;PRLV EDF CLIENT 123;-63,40', '06/11/2025;X 1;-5,00');

        $result = $this->validation->validate($this->admin, [$this->row($ids['PRLV EDF CLIENT 123'], ['category' => 'electricite', 'voucherRef' => 'Facture EDF', 'remember' => true])], $this->clock);

        self::assertSame(1, $result['rules']);
        $rule = $this->pdo->query('SELECT pattern, direction, category, payment_method FROM accounting_label_rules')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['prlv edf client', 'depense', 'electricite', 'virement'], array_values($rule));
        try {
            $this->validation->validate($this->admin, [$this->row($ids['X 1'], ['category' => 'materiel', 'voucherRef' => 'T', 'remember' => true])], $this->clock);
            self::fail('motif trop court');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey($ids['X 1'] . '.rulePattern', $e->fields());
        }
        self::assertSame(1, $this->rows('accounting_label_rules'));
    }

    #[Test]
    public function testACustomPatternMustBeTakenFromTheLabel(): void
    {
        $ids = $this->stage('05/11/2025;PRLV EDF CLIENT 123;-63,40');

        try {
            $this->validation->validate($this->admin, [$this->row($ids['PRLV EDF CLIENT 123'], ['category' => 'electricite', 'voucherRef' => 'F', 'remember' => true, 'rulePattern' => 'tout autre texte'])], $this->clock);
            self::fail('motif étranger');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey($ids['PRLV EDF CLIENT 123'] . '.rulePattern', $e->fields());
        }
        $this->validation->validate($this->admin, [$this->row($ids['PRLV EDF CLIENT 123'], ['category' => 'electricite', 'voucherRef' => 'F', 'remember' => true, 'rulePattern' => 'PRLV EDF'])], $this->clock);
        self::assertSame('prlv edf', (string) $this->pdo->query('SELECT pattern FROM accounting_label_rules')->fetchColumn());
    }

    #[Test]
    public function testAMatchedRecurringDueIsConfirmedInTheSameTransactionAndCannotBeUsedTwice(): void
    {
        $template = $this->templates->create($this->admin, ['label' => 'Loyer du local', 'direction' => 'depense', 'category' => 'loyer', 'paymentMethod' => 'virement', 'amount' => '498,00', 'voucherRef' => 'Virement loyer', 'periodicity' => 'trimestrielle', 'dueDay' => '5', 'firstMonth' => '2025-10'], $this->clock);
        $ids = $this->stage('06/10/2025;PRLV LOYER;-498,00', '07/10/2025;PRLV LOYER BIS;-498,00');
        $occurrence = $template . '|2025-10-01';

        $this->validation->validate($this->admin, [$this->row($ids['PRLV LOYER'], ['category' => 'loyer', 'voucherRef' => 'Virement loyer', 'occurrence' => $occurrence])], $this->clock);

        self::assertSame('confirmee', (string) $this->pdo->query('SELECT status FROM accounting_recurring_occurrences')->fetchColumn());
        try {
            $this->validation->validate($this->admin, [$this->row($ids['PRLV LOYER BIS'], ['category' => 'loyer', 'voucherRef' => 'V', 'occurrence' => $occurrence])], $this->clock);
            self::fail('échéance déjà décidée');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey($ids['PRLV LOYER BIS'] . '.occurrence', $e->fields());
        }
        self::assertSame(1, $this->rows('accounting_entries'));
        self::assertCount(1, $this->import->pending($this->admin), 'la seconde ligne reste en attente');
    }

    #[Test]
    public function testAnOccurrenceThatDoesNotFitTheLineIsRefused(): void
    {
        $template = $this->templates->create($this->admin, ['label' => 'Loyer', 'direction' => 'depense', 'category' => 'loyer', 'paymentMethod' => 'virement', 'amount' => '498,00', 'voucherRef' => 'V', 'periodicity' => 'trimestrielle', 'dueDay' => '5', 'firstMonth' => '2025-10'], $this->clock);
        $ids = $this->stage('06/10/2025;AUTRE MONTANT;-100,00', '07/10/2025;ECHEANCE HORS RYTHME;-498,00');

        foreach ([[$ids['AUTRE MONTANT'], $template . '|2025-10-01'], [$ids['ECHEANCE HORS RYTHME'], $template . '|2025-11-01'], [$ids['ECHEANCE HORS RYTHME'], '99999999|2025-10-01'], [$ids['ECHEANCE HORS RYTHME'], 'n\'importe quoi']] as [$line, $occurrence]) {
            try {
                $this->validation->validate($this->admin, [$this->row($line, ['category' => 'loyer', 'voucherRef' => 'V', 'occurrence' => $occurrence])], $this->clock);
                self::fail('refus attendu');
            } catch (AccountingValidationException $e) {
                self::assertArrayHasKey("{$line}.occurrence", $e->fields());
            }
        }
        self::assertSame(0, $this->rows('accounting_entries'));
    }

    #[Test]
    public function testAProposedDuesLineBecomesTheMembersDuesAndTheMonthIsThenPaid(): void
    {
        $ids = $this->stage('14/11/2025;VIR MUSICIEN COTIS;20,00');

        $this->validation->validate($this->admin, [$this->row($ids['VIR MUSICIEN COTIS'], ['category' => 'cotisations', 'memberUserId' => (string) $this->musician->id(), 'periodMonth' => '2025-11'])], $this->clock);

        self::assertSame($this->musician->id(), (int) $this->pdo->query('SELECT member_user_id FROM accounting_entries')->fetchColumn());
        self::assertSame('2025-11-01', (string) $this->pdo->query('SELECT period_month FROM accounting_entries')->fetchColumn());
    }

    #[Test]
    public function testNonAdministratorsCannotValidateAndNothingIsWritten(): void
    {
        $ids = $this->stage('05/11/2025;A;10,00');
        $denied = 0;
        foreach ([$this->musician, $this->gone] as $actor) {
            try {
                $this->validation->validate($actor, [$this->row($ids['A'], ['category' => 'autres_recettes'])]);
            } catch (AccessDeniedException) {
                ++$denied;
            }
        }
        self::assertSame(2, $denied);
        self::assertSame(0, $this->rows('accounting_entries'));
        self::assertCount(1, $this->import->pending($this->admin));
    }
}
