<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Http;

use App\Account\Entity\UserRole;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

/** #362 livraison 9 : rapport imprimable, export CSV et chiffres de l'AG de bout en bout : droits, identifiants, en-têtes, échappement, formules. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class AccountingReportHttpTest extends RepositoryTestCase
{
    use HttpHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeUser('admin', UserRole::Admin);
        $this->makeUser('musicien', UserRole::Musicien);
    }

    private function year(): int
    {
        [, $body] = $this->call('admin', 'POST', '/api/admin/accounting/years', ['label' => '2025-2026', 'startsOn' => '2025-10-01', 'endsOn' => '2026-09-30', 'openingBalance' => '100,00']);
        $id = (int) $this->json($body)['id'];
        $this->call('admin', 'POST', "/api/admin/accounting/years/{$id}/entries", ['occurredOn' => '2025-11-05', 'label' => '=HYPERLINK("http://x.test")', 'amount' => '20,00', 'direction' => 'recette', 'category' => 'cotisations', 'paymentMethod' => 'virement']);
        $this->call('admin', 'POST', "/api/admin/accounting/years/{$id}/entries", ['occurredOn' => '2025-11-06', 'label' => '<script>alert(1)</script>', 'amount' => '5,00', 'direction' => 'depense', 'category' => 'loyer', 'paymentMethod' => 'virement', 'voucherRef' => 'F-1']);

        return $id;
    }

    /** @return list<string> */
    private function urls(int|string $id): array
    {
        return ["/admin/accounting/years/{$id}/report", "/admin/accounting/years/{$id}/export.csv"];
    }

    #[Test]
    public function testTheReportShowsTheFiguresAndEscapesEverything(): void
    {
        $id = $this->year();

        [$status, $html] = $this->call('admin', 'GET', "/admin/accounting/years/{$id}/report");

        self::assertSame(200, $status);
        self::assertStringContainsString("100,00\u{a0}€", $html);
        self::assertStringContainsString('provisoires', $html);
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('onclick=', $html);
        self::assertDoesNotMatchRegularExpression('/<style|style="/i', $html);
    }

    #[Test]
    public function testTheExportIsASpreadsheetAttachmentWithNeutralisedFormulas(): void
    {
        $id = $this->year();

        [$status, $body, $headers] = $this->call('admin', 'GET', "/admin/accounting/years/{$id}/export.csv");

        self::assertSame(200, $status);
        self::assertStringStartsWith("\xEF\xBB\xBF", $body);
        self::assertStringContainsString("'=HYPERLINK", $body);
        self::assertStringNotContainsString(';=HYPERLINK', $body);
        self::assertSame("attachment; filename=\"comptabilite-exercice-{$id}.csv\"", $headers['Content-Disposition']);
        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertStringContainsString('no-store', $headers['Cache-Control']);
        self::assertStringContainsString('text/csv', $headers['Content-Type']);
    }

    #[Test]
    public function testAnonymousIsRedirectedAMusicianIsForbiddenAndNothingLeaks(): void
    {
        $id = $this->year();

        foreach ($this->urls($id) as $url) {
            self::assertSame(302, $this->call('anon', 'GET', $url)[0], $url);
            [$status, $body] = $this->call('musicien', 'GET', $url);
            self::assertSame(403, $status, $url);
            self::assertStringNotContainsString('Loyer', $body);
        }
        self::assertSame(401, $this->call('anon', 'GET', '/api/admin/accounting/figures')[0]);
        self::assertSame(403, $this->call('musicien', 'GET', '/api/admin/accounting/figures')[0]);
    }

    #[Test]
    public function testMalformedIdsAreForbiddenAndUnknownOnesAre404(): void
    {
        $this->year();
        foreach (['0', '-1', 'abc', '1e0', '007', '99999999999999999999', '%00'] as $bad) {
            foreach ($this->urls($bad) as $url) {
                self::assertSame(403, $this->call('admin', 'GET', $url)[0], $url);
            }
        }
        foreach ($this->urls('99999999') as $url) {
            [$status, $body] = $this->call('admin', 'GET', $url);
            self::assertSame(404, $status, $url);
            self::assertStringNotContainsString('99999999', $body);
        }
    }

    #[Test]
    public function testTheFiguresEndpointGivesPlainTextForTheLatestOrAChosenYear(): void
    {
        $id = $this->year();

        [$status, $body, $headers] = $this->call('admin', 'GET', '/api/admin/accounting/figures');
        self::assertSame(200, $status);
        self::assertStringContainsString('exercice 2025-2026', $this->json($body)['text']);
        self::assertStringContainsString("Recettes : 20,00\u{a0}€", $this->json($body)['text']);
        self::assertStringContainsString('no-store', $headers['Cache-Control'] ?? '');
        self::assertSame(200, $this->call('admin', 'GET', '/api/admin/accounting/figures', [], ['year' => (string) $id])[0]);
        self::assertSame(404, $this->call('admin', 'GET', '/api/admin/accounting/figures', [], ['year' => '99999999'])[0]);
        self::assertSame(403, $this->call('admin', 'GET', '/api/admin/accounting/figures', [], ['year' => 'abc'])[0]);
    }

    #[Test]
    public function testTheFiguresEndpointWithoutAnyYearIs404(): void
    {
        self::assertSame(404, $this->call('admin', 'GET', '/api/admin/accounting/figures')[0]);
    }
}
