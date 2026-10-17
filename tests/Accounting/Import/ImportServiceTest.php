<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Import;

use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Import\ImportLine;
use App\Accounting\Import\ImportService;
use App\Accounting\Import\Parse\CsvImportParser;
use App\Accounting\Import\Parse\ImportProfilePolicy;
use App\Accounting\Repository\Import\MysqlImportRepository;
use App\Database\TransactionRunner;
use App\Security\AdminAccess;
use App\Security\Exception\AccessDeniedException;
use App\Tests\Accounting\Service\AccountingTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;

/** #362 livraison 7 : mise en attente d'un CSV de banque : rien dans le livre, doublons, fichier déjà importé, purge, profil mémorisé, droits, journal sobre. */
final class ImportServiceTest extends AccountingTestCase
{
    private ImportService $import;
    private MysqlImportRepository $repository;
    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new MysqlImportRepository($this->pdo);
        $sink = &$this->log;
        $logger = new class ($sink) extends AbstractLogger {
            /** @param list<string> $sink */
            public function __construct(private array &$sink)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->sink[] = $message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
            }
        };
        $this->import = new ImportService(new AdminAccess(), new ImportProfilePolicy(), new CsvImportParser(), $this->repository, new TransactionRunner($this->pdo), $logger);
    }

    /** @return array<string, mixed> */
    private function profile(): array
    {
        return ['delimiter' => 'semicolon', 'encoding' => 'UTF-8', 'skipRows' => '1', 'dateColumn' => '1', 'labelColumn' => '2', 'amountMode' => 'signed', 'amountColumn' => '3', 'decimalSeparator' => ',', 'dateFormat' => 'd/m/Y'];
    }

    private function csv(string ...$rows): string
    {
        return "Date;Libellé;Montant\n" . implode("\n", $rows) . "\n";
    }

    private function rows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    #[Test]
    public function testAFileIsStagedAndNothingEntersTheLedger(): void
    {
        $result = $this->import->stage($this->admin, $this->csv('05/11/2025;VIR COTISATION JEAN;20,00', '06/11/2025;PRLV LOYER;-166,00', '07/11/2025;mal;abc'), $this->profile(), $this->clock);

        self::assertSame(['staged' => 2, 'duplicates' => 0, 'errors' => 1], array_diff_key($result, ['batchId' => 0]));
        self::assertSame(2, $this->rows('accounting_import_lines'));
        self::assertSame(0, $this->rows('accounting_entries'), 'rien n\'entre dans le livre');
        self::assertSame(2, count($this->import->pending($this->admin)));
    }

    #[Test]
    public function testTheSameFileCannotBeImportedTwice(): void
    {
        $csv = $this->csv('05/11/2025;A;1,00');
        $this->import->stage($this->admin, $csv, $this->profile(), $this->clock);

        try {
            $this->import->stage($this->admin, $csv, $this->profile(), $this->clock);
            self::fail('refus attendu');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey('file', $e->fields());
        }
        self::assertSame(1, $this->rows('accounting_import_lines'));
        self::assertSame(1, $this->rows('accounting_import_batches'));
    }

    #[Test]
    public function testALineAlreadySeenInAnotherFileIsNotStagedAgainButTwoIdenticalLinesOfOneFileAre(): void
    {
        $this->import->stage($this->admin, $this->csv('05/11/2025;CAFE;-3,00', '05/11/2025;CAFE;-3,00'), $this->profile(), $this->clock);
        self::assertSame(2, $this->rows('accounting_import_lines'), 'deux cafés identiques le même jour : deux lignes');

        $second = $this->import->stage($this->admin, $this->csv('05/11/2025;CAFE;-3,00', '05/11/2025;cafe;-3,00', '08/11/2025;NOUVEAU;-1,00'), $this->profile(), $this->clock);

        self::assertSame(1, $second['staged'], 'seule la ligne nouvelle');
        self::assertSame(2, $second['duplicates']);
        self::assertSame(3, $this->rows('accounting_import_lines'));
    }

    #[Test]
    public function testAFileWithoutAnyReadableLineIsRefusedAndLeavesNothing(): void
    {
        foreach ([$this->csv('abc;;'), '', "%PDF-1.4\0binaire"] as $content) {
            try {
                $this->import->stage($this->admin, $content, $this->profile(), $this->clock);
                self::fail('refus attendu');
            } catch (AccountingValidationException $e) {
                self::assertArrayHasKey('file', $e->fields());
            }
        }
        self::assertSame(0, $this->rows('accounting_import_batches'));
        self::assertSame(0, $this->rows('accounting_import_hashes'));
    }

    #[Test]
    public function testAnInvalidProfileIsRefusedByFieldBeforeAnythingIsRead(): void
    {
        try {
            $this->import->stage($this->admin, $this->csv('05/11/2025;A;1,00'), ['delimiter' => 'x'] + $this->profile(), $this->clock);
            self::fail('refus attendu');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey('delimiter', $e->fields());
        }
        self::assertSame(0, $this->rows('accounting_import_batches'));
    }

    #[Test]
    public function testThePreviewWritesNothing(): void
    {
        $result = $this->import->preview($this->admin, $this->csv('05/11/2025;A;1,00'), $this->profile());

        self::assertCount(1, $result->lines);
        self::assertSame(0, $this->rows('accounting_import_batches'));
        self::assertSame(0, $this->rows('accounting_import_lines'));
        self::assertNull($this->import->savedProfile($this->admin), 'l\'aperçu ne mémorise pas le profil');
    }

    #[Test]
    public function testTheLastUsedProfileIsRememberedAfterAStaging(): void
    {
        try {
            $this->import->stage($this->admin, $this->csv('05/11/2025;A;1,00'), ['delimiter' => 'comma', 'amountColumn' => '4', 'dateFormat' => 'Y-m-d'] + $this->profile(), $this->clock);
            self::fail('le fichier est en point-virgule : aucune ligne lisible');
        } catch (AccountingValidationException) {
        }
        self::assertNull($this->import->savedProfile($this->admin), 'un essai raté ne mémorise rien');

        $this->import->stage($this->admin, $this->csv('05/11/2025;B;2,00'), $this->profile(), $this->clock);

        $saved = $this->import->savedProfile($this->admin);
        self::assertSame([';', 'd/m/Y', 3], [$saved?->delimiter, $saved?->dateFormat, $saved?->amountColumn]);
    }

    #[Test]
    public function testALineCanBeDiscardedOnceAndNeverComesBack(): void
    {
        $csv = $this->csv('05/11/2025;A;1,00');
        $this->import->stage($this->admin, $csv, $this->profile(), $this->clock);
        $line = $this->import->pending($this->admin)[0];

        self::assertTrue($this->import->discard($this->admin, $line->id, $this->clock));
        self::assertFalse($this->import->discard($this->admin, $line->id, $this->clock));
        self::assertSame([], $this->import->pending($this->admin));
        $again = $this->import->stage($this->admin, $this->csv('05/11/2025;A;1,00', '09/11/2025;Autre;2,00'), $this->profile(), $this->clock);
        self::assertSame([1, 1], [$again['staged'], $again['duplicates']], 'la ligne écartée reste connue');
    }

    #[Test]
    public function testThePurgeRemovesOldLinesButKeepsTheirHashes(): void
    {
        $this->import->stage($this->admin, $this->csv('05/11/2025;A;1,00'), $this->profile(), new \DateTimeImmutable('2026-01-01 10:00:00'));
        $fresh = $this->import->stage($this->admin, $this->csv('06/11/2025;B;2,00'), $this->profile(), new \DateTimeImmutable('2026-01-25 10:00:00'));

        $deleted = $this->import->purgeExpired(new \DateTimeImmutable('2026-02-05 10:00:00'));

        self::assertSame(1, $deleted);
        self::assertSame(1, $this->rows('accounting_import_lines'));
        self::assertSame(2, $this->rows('accounting_import_hashes'), 'les empreintes restent : une ligne purgée ne revient pas');
        self::assertSame($fresh['batchId'], (int) $this->pdo->query('SELECT batch_id FROM accounting_import_lines')->fetchColumn());
    }

    #[Test]
    public function testNonAdministratorsCannotPreviewStageListDiscardOrPurgeNothingIsWritten(): void
    {
        $denied = 0;
        foreach ([$this->musician, $this->gone] as $actor) {
            foreach ([
                fn () => $this->import->preview($actor, $this->csv('05/11/2025;A;1,00'), $this->profile()),
                fn () => $this->import->stage($actor, $this->csv('05/11/2025;A;1,00'), $this->profile()),
                fn () => $this->import->pending($actor),
                fn () => $this->import->discard($actor, 1),
                fn () => $this->import->savedProfile($actor),
            ] as $call) {
                try {
                    $call();
                } catch (AccessDeniedException) {
                    ++$denied;
                }
            }
        }
        self::assertSame(10, $denied);
        self::assertSame(0, $this->rows('accounting_import_batches'));
    }

    #[Test]
    public function testTheLogNeverReceivesALabelNorAnAmount(): void
    {
        $this->import->stage($this->admin, $this->csv('05/11/2025;VIREMENT FR76 3000 SECRET;-12,34'), $this->profile(), $this->clock);

        $log = implode("\n", $this->log);
        self::assertStringContainsString('mis en attente', $log);
        self::assertStringNotContainsString('FR76', $log);
        self::assertStringNotContainsString('SECRET', $log);
        self::assertStringNotContainsString('1234', $log);
    }

    #[Test]
    public function testAPendingLineKeepsTheRawTextAndTheLinesStayInStatePending(): void
    {
        $this->import->stage($this->admin, $this->csv('05/11/2025;=HYPERLINK("http://x.test");1,00'), $this->profile(), $this->clock);

        $line = $this->import->pending($this->admin)[0];

        self::assertSame('=HYPERLINK("http://x.test")', $line->label);
        self::assertSame(ImportLine::PENDING, $line->status);
        self::assertTrue($line->isPending());
    }
}
