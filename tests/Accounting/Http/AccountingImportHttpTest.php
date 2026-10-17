<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Http;

use App\Account\Entity\UserRole;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/** #362 livraison 7 : import du CSV de la banque de bout en bout : droits, CSRF, fichiers hostiles, aperçu sans écriture, validation en lot, règles, XSS. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class AccountingImportHttpTest extends RepositoryTestCase
{
    use HttpHarness;

    private string $upload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeUser('admin', UserRole::Admin);
        $this->makeUser('musicien', UserRole::Musicien);
        $this->upload = sys_get_temp_dir() . '/rb-imp-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->upload);
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function profile(): array
    {
        return ['delimiter' => 'semicolon', 'encoding' => 'UTF-8', 'skipRows' => '1', 'dateColumn' => '1', 'labelColumn' => '2', 'amountMode' => 'signed', 'amountColumn' => '3', 'decimalSeparator' => ',', 'dateFormat' => 'd/m/Y'];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    private function send(string $actor, string $path, string $content, array $fields = [], bool $csrf = true, int $error = UPLOAD_ERR_OK): array
    {
        file_put_contents($this->upload, $content);
        $files = ['file' => ['name' => 'releve.csv', 'type' => 'text/csv', 'tmp_name' => $this->upload, 'error' => $error, 'size' => strlen($content)]];

        return $this->call($actor, 'POST', $path, $fields + $this->profile(), [], $csrf, $files);
    }

    private function csv(string ...$rows): string
    {
        return "Date;Libellé;Montant\n" . implode("\n", $rows) . "\n";
    }

    private function year(): int
    {
        [, $body] = $this->call('admin', 'POST', '/api/admin/accounting/years', ['label' => '2025-2026', 'startsOn' => '2025-10-01', 'endsOn' => '2026-09-30', 'openingBalance' => '0']);

        return (int) $this->json($body)['id'];
    }

    private function rows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    #[Test]
    public function testThePreviewShowsTheLinesAndTheRefusedOnesAndWritesNothing(): void
    {
        [$status, $body] = $this->send('admin', '/api/admin/accounting/imports/preview', $this->csv('05/11/2025;VIR COTIS;20,00', '06/11/2025;PRLV;abc'));

        self::assertSame(200, $status, $body);
        $json = $this->json($body);
        self::assertSame(1, $json['total']);
        self::assertSame(['record' => 2, 'date' => '05/11/2025', 'label' => 'VIR COTIS', 'cents' => 2000, 'direction' => 'recette'], $json['lines'][0]);
        self::assertArrayHasKey('3', $json['errors']);
        self::assertSame(0, $this->rows('accounting_import_lines'));
        self::assertSame(0, $this->rows('accounting_import_batches'));
    }

    #[Test]
    public function testStagingThenValidatingTurnsLinesIntoEntries(): void
    {
        $this->year();
        [$status, $body] = $this->send('admin', '/api/admin/accounting/imports', $this->csv('05/11/2025;VIR DON;12,50', '06/11/2025;ACHAT;-30,00'));
        self::assertSame(201, $status, $body);
        self::assertSame(['staged' => 2, 'duplicates' => 0, 'errors' => 0], array_intersect_key($this->json($body), array_flip(['staged', 'duplicates', 'errors'])));
        self::assertSame(0, $this->rows('accounting_entries'));
        $ids = array_column($this->pdo->query('SELECT label, id FROM accounting_import_lines')->fetchAll(\PDO::FETCH_NUM), 1, 0);

        [$status, $body] = $this->call('admin', 'POST', '/api/admin/accounting/imports/validate', ['rows' => [
            ['line' => (string) $ids['VIR DON'], 'category' => 'autres_recettes', 'paymentMethod' => 'virement'],
            ['line' => (string) $ids['ACHAT'], 'category' => 'materiel', 'paymentMethod' => 'carte', 'voucherRef' => 'Ticket', 'remember' => true],
        ]]);

        self::assertSame(200, $status, $body);
        self::assertSame(['entries' => 2, 'rules' => 1], array_intersect_key($this->json($body), array_flip(['entries', 'rules'])));
        self::assertSame(2, $this->rows('accounting_entries'));
        self::assertSame(1, $this->rows('accounting_label_rules'));
        [, $page] = $this->call('admin', 'GET', '/admin/accounting/imports');
        self::assertStringContainsString('Aucune ligne en attente', $page);
    }

    #[Test]
    public function testAnInvalidRowIs422WithKeysByLineAndNothingIsWritten(): void
    {
        $this->year();
        $this->send('admin', '/api/admin/accounting/imports', $this->csv('06/11/2025;ACHAT;-30,00'));
        $id = (int) $this->pdo->query('SELECT id FROM accounting_import_lines')->fetchColumn();

        [$status, $body] = $this->call('admin', 'POST', '/api/admin/accounting/imports/validate', ['rows' => [['line' => (string) $id, 'category' => 'materiel', 'paymentMethod' => 'carte']]]);

        self::assertSame(422, $status);
        self::assertArrayHasKey("{$id}.voucherRef", $this->json($body)['fields']);
        self::assertSame(0, $this->rows('accounting_entries'));
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM accounting_import_lines WHERE status = 'en_attente'")->fetchColumn());
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function writes(): iterable
    {
        yield 'aperçu' => ['POST', '/api/admin/accounting/imports/preview'];
        yield 'mise en attente' => ['POST', '/api/admin/accounting/imports'];
        yield 'validation' => ['POST', '/api/admin/accounting/imports/validate'];
        yield 'écarter' => ['POST', '/api/admin/accounting/imports/lines/1/discard'];
        yield 'modifier une règle' => ['PUT', '/api/admin/accounting/rules/1'];
        yield 'supprimer une règle' => ['DELETE', '/api/admin/accounting/rules/1'];
    }

    #[Test]
    #[DataProvider('writes')]
    public function testAnonymousGets401AMusicianGets403AndAMissingCsrfIsRefused(string $method, string $path): void
    {
        $this->year();
        $body = ['rows' => []] + $this->profile();
        file_put_contents($this->upload, $this->csv('05/11/2025;A;1,00'));
        $files = ['file' => ['name' => 'a.csv', 'type' => 'text/csv', 'tmp_name' => $this->upload, 'error' => UPLOAD_ERR_OK, 'size' => 40]];

        self::assertSame(401, $this->call('anon', $method, $path, $body, [], true, $files)[0]);
        self::assertSame(403, $this->call('musicien', $method, $path, $body, [], true, $files)[0]);
        self::assertSame(403, $this->call('admin', $method, $path, $body, [], false, $files)[0]);
        self::assertSame(0, $this->rows('accounting_import_batches'));
        self::assertSame(0, $this->rows('accounting_import_lines'));
    }

    #[Test]
    public function testHostileFilesAreRefusedWithAMessageOnTheFileField(): void
    {
        foreach (['', "%PDF-1.4\0binaire", "<?php system('id');", str_repeat('x', 1_048_577)] as $content) {
            [$status, $body] = $this->send('admin', '/api/admin/accounting/imports', $content);
            self::assertSame(422, $status, substr($content, 0, 12));
            self::assertArrayHasKey('file', $this->json($body)['fields']);
        }
        [$status, $body] = $this->send('admin', '/api/admin/accounting/imports', $this->csv('05/11/2025;A;1,00'), [], true, UPLOAD_ERR_INI_SIZE);
        self::assertSame(422, $status);
        self::assertArrayHasKey('file', $this->json($body)['fields']);
        [$status] = $this->call('admin', 'POST', '/api/admin/accounting/imports', $this->profile());
        self::assertSame(422, $status, 'sans fichier');
        self::assertSame(0, $this->rows('accounting_import_batches'));
    }

    #[Test]
    public function testAnInvalidProfileIs422ByField(): void
    {
        [$status, $body] = $this->send('admin', '/api/admin/accounting/imports/preview', $this->csv('05/11/2025;A;1,00'), ['delimiter' => 'pipe', 'dateColumn' => '0']);

        self::assertSame(422, $status);
        self::assertEqualsCanonicalizing(['delimiter', 'dateColumn'], array_keys($this->json($body)['fields']));
    }

    #[Test]
    public function testTheSameFileTwiceIs422AndAnIdMalformedIsForbidden(): void
    {
        $csv = $this->csv('05/11/2025;A;1,00');
        self::assertSame(201, $this->send('admin', '/api/admin/accounting/imports', $csv)[0]);
        self::assertSame(422, $this->send('admin', '/api/admin/accounting/imports', $csv)[0]);

        foreach (['0', '-1', 'abc', '1e0', '007', '99999999999999999999', '%00'] as $bad) {
            self::assertSame(403, $this->call('admin', 'POST', "/api/admin/accounting/imports/lines/{$bad}/discard")[0], $bad);
            self::assertSame(403, $this->call('admin', 'PUT', "/api/admin/accounting/rules/{$bad}", ['category' => 'materiel', 'paymentMethod' => 'carte'])[0], $bad);
            self::assertSame(403, $this->call('admin', 'DELETE', "/api/admin/accounting/rules/{$bad}")[0], $bad);
        }
        self::assertSame(404, $this->call('admin', 'DELETE', '/api/admin/accounting/rules/99999999')[0]);
    }

    #[Test]
    public function testDiscardingALineRemovesItFromThePage(): void
    {
        $this->send('admin', '/api/admin/accounting/imports', $this->csv('05/11/2025;LIGNE A ECARTER;1,00'));
        $id = (int) $this->pdo->query('SELECT id FROM accounting_import_lines')->fetchColumn();

        self::assertSame(200, $this->call('admin', 'POST', "/api/admin/accounting/imports/lines/{$id}/discard")[0]);

        [, $html] = $this->call('admin', 'GET', '/admin/accounting/imports');
        self::assertStringNotContainsString('LIGNE A ECARTER', $html);
    }

    #[Test]
    public function testRulesCanBeRetargetedAndDeletedAndAWrongCategoryIs422(): void
    {
        $this->year();
        $this->send('admin', '/api/admin/accounting/imports', $this->csv('06/11/2025;PRLV EDF CLIENT;-63,40'));
        $line = (int) $this->pdo->query('SELECT id FROM accounting_import_lines')->fetchColumn();
        $this->call('admin', 'POST', '/api/admin/accounting/imports/validate', ['rows' => [['line' => (string) $line, 'category' => 'electricite', 'paymentMethod' => 'virement', 'voucherRef' => 'F', 'remember' => true]]]);
        $rule = (int) $this->pdo->query('SELECT id FROM accounting_label_rules')->fetchColumn();

        self::assertSame(422, $this->call('admin', 'PUT', "/api/admin/accounting/rules/{$rule}", ['category' => 'cotisations', 'paymentMethod' => 'virement'])[0], 'catégorie d\'un autre sens');
        self::assertSame(200, $this->call('admin', 'PUT', "/api/admin/accounting/rules/{$rule}", ['category' => 'assurances', 'paymentMethod' => 'carte'])[0]);
        self::assertSame('assurances', (string) $this->pdo->query('SELECT category FROM accounting_label_rules')->fetchColumn());
        self::assertSame(200, $this->call('admin', 'DELETE', "/api/admin/accounting/rules/{$rule}")[0]);
        self::assertSame(0, $this->rows('accounting_label_rules'));
    }

    #[Test]
    public function testPagesAreForAdministratorsAndEscapeBankLabelsAndShowSuggestions(): void
    {
        $this->year();
        $this->send('admin', '/api/admin/accounting/imports', $this->csv('06/11/2025;<img src=x onerror=alert(1)>;-5,00', '07/11/2025;=HYPERLINK("http://x.test");1,00'));

        [$status, $html] = $this->call('admin', 'GET', '/admin/accounting/imports');

        self::assertSame(200, $status);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img', $html);
        self::assertStringContainsString('À catégoriser', $html);
        self::assertDoesNotMatchRegularExpression('/<style|style="|onclick=/i', $html);
        foreach (['/admin/accounting/imports', '/admin/accounting/rules'] as $path) {
            self::assertSame(302, $this->call('anon', 'GET', $path)[0]);
            self::assertSame(403, $this->call('musicien', 'GET', $path)[0]);
            self::assertSame(200, $this->call('admin', 'GET', $path)[0]);
        }
    }
}
