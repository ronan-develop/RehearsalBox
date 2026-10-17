<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Export;

use App\Accounting\Export\CsvResponse;
use App\Accounting\Export\LedgerExportService;
use App\Accounting\Exception\FiscalYearNotFoundException;
use App\Accounting\Repository\MysqlMemberDuesRepository;
use App\Security\AdminAccess;
use App\Security\Exception\AccessDeniedException;
use App\Tests\Accounting\Service\AccountingTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

/** #362 : export complet d'un exercice : droits jugés avant l'envoi, montants à la française, formules neutralisées, annulations, adhérent. */
final class LedgerExportServiceTest extends AccountingTestCase
{
    private LedgerExportService $export;

    protected function setUp(): void
    {
        parent::setUp();
        $this->export = new LedgerExportService(new AdminAccess(), $this->yearRepository, $this->entryRepository, new MysqlMemberDuesRepository($this->pdo), new NullLogger());
    }

    /** @return list<list<string>> */
    private function rows(int $year): array
    {
        return iterator_to_array($this->export->rows($this->admin, $year), false);
    }

    #[Test]
    public function testTheExportHasAHeaderAndOneLinePerEntryWithFrenchAmounts(): void
    {
        $year = $this->openYear();
        $this->record($year, ['amount' => '1234,50']);
        $this->expense($year, '156,00');

        $rows = $this->rows($year);

        self::assertCount(3, $rows);
        self::assertSame('Numéro', $rows[0][0]);
        self::assertSame(['1', '05/11/2025', 'Cotisation', 'Cotisations', 'Argent reçu', '1234,50', '', 'Virement'], array_slice($rows[1], 0, 8));
        self::assertSame(['2', '05/11/2025', 'Loyer', 'Loyer', 'Argent dépensé', '', '156,00', 'Virement'], array_slice($rows[2], 0, 8));
    }

    #[Test]
    public function testHostileTextIsNeutralisedInEveryTextColumn(): void
    {
        $year = $this->openYear();
        $this->record($year, ['label' => '=HYPERLINK("http://x.test","a")', 'voucherRef' => '+33612345678', 'note' => '@SUM(A1)']);

        $row = $this->rows($year)[1];

        self::assertSame("'=HYPERLINK(\"http://x.test\",\"a\")", $row[2]);
        self::assertSame("'+33612345678", $row[8]);
        self::assertSame("'@SUM(A1)", $row[9]);
    }

    #[Test]
    public function testAReversalPointsToTheOriginalNumberAndADuesLineNamesTheMember(): void
    {
        $year = $this->openYear();
        $dues = $this->record($year, ['category' => 'cotisations', 'memberUserId' => (string) $this->musician->id(), 'periodMonth' => '2025-11']);
        $this->entries->reverse($this->admin, $dues->id, ['reason' => 'Erreur', 'occurredOn' => '2025-11-06'], $this->clock);

        [, $original, $reversal] = $this->rows($year);

        self::assertSame('Musicien', $original[10]);
        self::assertSame('novembre 2025', $original[11]);
        self::assertSame('1', $reversal[12]);
    }

    #[Test]
    public function testOnlyAdministratorsExportAndAnUnknownYearIsRefusedBeforeAnyLine(): void
    {
        $year = $this->openYear();
        foreach ([$this->musician, $this->gone] as $actor) {
            try {
                $this->export->rows($actor, $year);
                self::fail('refus attendu');
            } catch (AccessDeniedException) {
            }
        }
        $this->expectException(FiscalYearNotFoundException::class);
        $this->export->rows($this->admin, 99999999);
    }

    #[Test]
    public function testTheCsvResponseIsAFrenchSpreadsheetAttachmentWithAFixedName(): void
    {
        $year = $this->openYear();
        $this->record($year, ['label' => 'Libellé; avec "guillemets"']);

        $response = new CsvResponse($this->export->rows($this->admin, $year), 'comptabilite.csv');
        $body = $response->body();

        self::assertStringStartsWith("\xEF\xBB\xBF", $body);
        self::assertStringContainsString("Numéro;Date;Libellé;", $body);
        self::assertStringContainsString('"Libellé; avec ""guillemets"""', $body);
        self::assertStringContainsString("\"deux\nlignes\"", (new CsvResponse([["deux\nlignes"]], 'x.csv'))->body());
        self::assertSame('attachment; filename="comptabilite.csv"', $response->headers()['Content-Disposition']);
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
        self::assertStringContainsString('no-store', $response->headers()['Cache-Control']);
    }

    #[Test]
    public function testMoreThanOnePageOfEntriesIsStreamedWithoutLossOrDuplicate(): void
    {
        $year = $this->openYear();
        $insert = $this->pdo->prepare('INSERT INTO accounting_entries (fiscal_year_id, entry_number, occurred_on, label, amount_cents, direction, category, payment_method, created_by_user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        for ($i = 1; $i <= 1203; ++$i) {
            $insert->execute([$year, $i, '2025-11-05', "Ligne {$i}", 100, 'recette', 'cotisations', 'virement', $this->admin->id(), '2025-11-05 10:00:00']);
        }

        $rows = $this->rows($year);

        self::assertCount(1204, $rows);
        self::assertSame(range(1, 1203), array_map('intval', array_column(array_slice($rows, 1), 0)));
    }
}
