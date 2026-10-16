<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Http;

use App\Account\Entity\UserRole;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/** #362 livraison 4 : dépôt et téléchargement des relevés de bout en bout : droits, CSRF, identifiants, fichiers hostiles, en-têtes, racine web. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class AccountingStatementApiTest extends RepositoryTestCase
{
    use HttpHarness;

    private string $upload;
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeUser('admin', UserRole::Admin);
        $this->makeUser('musicien', UserRole::Musicien);
        $this->upload = sys_get_temp_dir() . '/rb-st-up-' . bin2hex(random_bytes(4));
        $config = require __DIR__ . '/../../../config/config.php';
        $this->storage = $config['storage']['accounting_statements_path'];
    }

    protected function tearDown(): void
    {
        @unlink($this->upload);
        foreach ($this->pdo->query('SELECT stored_name FROM accounting_statements')->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            @unlink($this->storage . '/' . $name);
        }
        parent::tearDown();
    }

    private static function pdf(string $marker = 'a'): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /M ({$marker}) >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function year(): int
    {
        [, $body] = $this->call('admin', 'POST', '/api/admin/accounting/years', ['label' => '2025-2026', 'startsOn' => '2025-10-01', 'endsOn' => '2026-09-30', 'openingBalance' => '100,00']);

        return (int) $this->json($body)['id'];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    private function send(string $actor, array $fields, ?string $content = null, string $name = 'releve.pdf', int $error = UPLOAD_ERR_OK, bool $csrf = true): array
    {
        $content ??= self::pdf();
        file_put_contents($this->upload, $content);
        $files = ['file' => ['name' => $name, 'type' => 'application/pdf', 'tmp_name' => $this->upload, 'error' => $error, 'size' => strlen($content)]];

        return $this->call($actor, 'POST', '/api/admin/accounting/statements', $fields, [], $csrf, $files);
    }

    /** @return array<string, string> */
    private function fields(int $year, array $more = []): array
    {
        return $more + ['fiscalYearId' => (string) $year, 'periodStart' => '2025-10-01', 'periodEnd' => '2025-10-31', 'closingBalance' => '120,00'];
    }

    private function rows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_statements')->fetchColumn();
    }

    #[Test]
    public function testAnAdministratorDepositsThenDownloadsAnIsolatedAttachment(): void
    {
        $year = $this->year();

        [$status, $body] = $this->send('admin', $this->fields($year));

        self::assertSame(201, $status, $body);
        $id = (int) $this->json($body)['id'];
        [$status, $content, $headers] = $this->call('admin', 'GET', "/admin/accounting/statements/{$id}/file");
        self::assertSame(200, $status);
        self::assertSame(self::pdf(), $content);
        self::assertStringStartsWith('attachment;', $headers['Content-Disposition']);
        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertStringContainsString('sandbox', $headers['Content-Security-Policy']);
        self::assertStringContainsString('no-store', $headers['Cache-Control']);
        self::assertStringContainsString('private', $headers['Cache-Control']);
    }

    #[Test]
    public function testAnImageIsServedAsAttachmentToo(): void
    {
        $year = $this->year();
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        imagepng($image);
        [, $body] = $this->send('admin', $this->fields($year), (string) ob_get_clean(), 'scan.png');

        [, , $headers] = $this->call('admin', 'GET', '/admin/accounting/statements/' . $this->json($body)['id'] . '/file');

        self::assertStringStartsWith('attachment;', $headers['Content-Disposition'], 'un relevé n\'est jamais affiché dans l\'origine du site');
    }

    #[Test]
    public function testAnonymousGets401AMusicianGets403AndNothingIsStored(): void
    {
        $year = $this->year();

        self::assertSame(401, $this->send('anon', $this->fields($year))[0]);
        self::assertSame(403, $this->send('musicien', $this->fields($year))[0]);
        self::assertSame(403, $this->send('admin', $this->fields($year), null, 'releve.pdf', UPLOAD_ERR_OK, false)[0], 'sans jeton CSRF');
        self::assertSame(0, $this->rows());
    }

    #[Test]
    public function testDownloadIsRefusedToAnonymousAndMusiciansAndUnknownIdsAre404(): void
    {
        $year = $this->year();
        $id = (int) $this->json($this->send('admin', $this->fields($year))[1])['id'];

        self::assertSame(302, $this->call('anon', 'GET', "/admin/accounting/statements/{$id}/file")[0]);
        self::assertSame(403, $this->call('musicien', 'GET', "/admin/accounting/statements/{$id}/file")[0]);
        [$status, $body] = $this->call('admin', 'GET', '/admin/accounting/statements/99999999/file');
        self::assertSame(404, $status);
        self::assertStringNotContainsString('99999999', $body);
        foreach (['0', '-1', 'abc', '1e0', '007', '99999999999999999999', '%00'] as $bad) {
            self::assertSame(403, $this->call('admin', 'GET', "/admin/accounting/statements/{$bad}/file")[0], $bad);
        }
    }

    #[Test]
    public function testAMissingFileOnDiskIs404NeverAnEmpty200(): void
    {
        $year = $this->year();
        $id = (int) $this->json($this->send('admin', $this->fields($year))[1])['id'];
        $stored = (string) $this->pdo->query("SELECT stored_name FROM accounting_statements WHERE id = {$id}")->fetchColumn();
        unlink($this->storage . '/' . $stored);

        self::assertSame(404, $this->call('admin', 'GET', "/admin/accounting/statements/{$id}/file")[0]);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function hostile(): iterable
    {
        yield 'script PHP' => ["<?php system('id');", 'releve.pdf'];
        yield 'double extension' => ["<?php system('id');", 'releve.pdf.php'];
        yield 'HTML' => ['<script>alert(1)</script>', 'releve.pdf'];
        yield 'PDF tronqué' => ["%PDF-1.4\n1 0 obj\n", 'releve.pdf'];
        yield 'vide' => ['', 'releve.pdf'];
    }

    #[Test]
    #[DataProvider('hostile')]
    public function testAHostileFileIs422OnTheFileFieldAndNothingStaysOnDisk(string $content, string $name): void
    {
        $year = $this->year();
        $before = glob($this->storage . '/*') ?: [];

        [$status, $body] = $this->send('admin', $this->fields($year), $content, $name);

        self::assertSame(422, $status, $body);
        self::assertArrayHasKey('file', $this->json($body)['fields']);
        self::assertSame(0, $this->rows());
        self::assertSame($before, glob($this->storage . '/*') ?: []);
    }

    #[Test]
    public function testAMissingOrFailedUploadIs422WithAClearMessage(): void
    {
        $year = $this->year();

        [$status, $body] = $this->call('admin', 'POST', '/api/admin/accounting/statements', $this->fields($year));
        self::assertSame(422, $status);
        self::assertArrayHasKey('file', $this->json($body)['fields']);
        foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_FILE] as $error) {
            [$status, $body] = $this->send('admin', $this->fields($year), null, 'releve.pdf', $error);
            self::assertSame(422, $status, (string) $error);
            self::assertArrayHasKey('file', $this->json($body)['fields']);
        }
    }

    #[Test]
    public function testInvalidFieldsAreReportedTogetherAndAnUnknownOrBadYearIsRefused(): void
    {
        $year = $this->year();

        [$status, $body] = $this->send('admin', $this->fields($year, ['periodStart' => 'hier', 'closingBalance' => 'abc']));
        self::assertSame(422, $status);
        self::assertArrayHasKey('periodStart', $this->json($body)['fields']);
        self::assertArrayHasKey('closingBalance', $this->json($body)['fields']);
        self::assertSame(404, $this->send('admin', $this->fields(99999999))[0]);
        foreach (['0', '-1', 'abc', '1e0', '007', '%00'] as $bad) {
            self::assertSame(403, $this->send('admin', $this->fields(0, ['fiscalYearId' => $bad]))[0], $bad);
        }
        self::assertSame(0, $this->rows());
    }

    #[Test]
    public function testAClosedYearIs409AndADuplicateIs422(): void
    {
        $year = $this->year();
        self::assertSame(201, $this->send('admin', $this->fields($year))[0]);

        self::assertSame(422, $this->send('admin', $this->fields($year, ['periodStart' => '2025-11-01', 'periodEnd' => '2025-11-30']))[0], 'même fichier');
        $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/close", ['revision' => (int) $this->pdo->query("SELECT revision FROM accounting_fiscal_years WHERE id = {$year}")->fetchColumn()]);
        self::assertSame(409, $this->send('admin', $this->fields($year), self::pdf('b'))[0]);
    }

    #[Test]
    public function testForgedFieldsInTheBodyAreIgnored(): void
    {
        $year = $this->year();

        $this->send('admin', $this->fields($year, ['storedName' => '../../x', 'stored_name' => 'x', 'sha256' => 'x', 'mime' => 'text/html', 'uploadedBy' => '777', 'replacedBy' => '1', 'id' => '5']));

        $row = $this->pdo->query('SELECT stored_name, sha256, mime, uploaded_by_user_id, replaced_by_id FROM accounting_statements')->fetch(\PDO::FETCH_ASSOC);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $row['stored_name']);
        self::assertSame(hash('sha256', self::pdf()), $row['sha256']);
        self::assertSame('application/pdf', $row['mime']);
        self::assertNotSame(777, (int) $row['uploaded_by_user_id']);
        self::assertNull($row['replaced_by_id']);
    }

    #[Test]
    public function testTheStorageFolderIsOutsideTheWebRoot(): void
    {
        $storage = realpath(dirname($this->storage)) ?: dirname($this->storage);
        $public = realpath(__DIR__ . '/../../../public');

        self::assertStringStartsNotWith((string) $public, $storage . '/');
    }

    #[Test]
    public function testThePageListsTheStatementsWithTheGapAndEscapesTheName(): void
    {
        $year = $this->year();
        $this->send('admin', $this->fields($year), null, '<img src=x onerror=alert(1)>.pdf');

        [$status, $html] = $this->call('admin', 'GET', '/admin/accounting/statements', [], ['year' => (string) $year]);

        self::assertSame(200, $status);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img', $html);
        self::assertStringContainsString("120,00\u{a0}€", $html);
        self::assertSame(302, $this->call('anon', 'GET', '/admin/accounting/statements')[0]);
        self::assertSame(403, $this->call('musicien', 'GET', '/admin/accounting/statements')[0]);
    }
}
