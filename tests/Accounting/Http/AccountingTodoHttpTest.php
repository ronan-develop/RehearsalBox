<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Http;

use App\Account\Entity\UserRole;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

/** #362 livraison 8 : « À faire » sur la page et « Rien à saisir ce mois-ci » de bout en bout : droits, CSRF, identifiants, mois, idempotence. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class AccountingTodoHttpTest extends RepositoryTestCase
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
        $start = date('Y-m-01', strtotime('-4 months'));
        [, $body] = $this->call('admin', 'POST', '/api/admin/accounting/years', ['label' => 'Courant', 'startsOn' => $start, 'endsOn' => date('Y-m-d', strtotime($start . ' +12 months -1 day')), 'openingBalance' => '0']);

        return (int) $this->json($body)['id'];
    }

    private function pastMonth(): string
    {
        return date('Y-m', strtotime('-2 months'));
    }

    #[Test]
    public function testThePageListsTheTasksOfFinishedMonths(): void
    {
        $year = $this->year();

        [$status, $html] = $this->call('admin', 'GET', '/admin/accounting', [], ['year' => (string) $year]);

        self::assertSame(200, $status);
        self::assertStringContainsString('data-todo', $html);
        self::assertStringContainsString('Déposer le relevé de', $html);
        self::assertStringContainsString('Rien à saisir ce mois-ci', $html);
        self::assertStringContainsString('En retard', $html);
    }

    #[Test]
    public function testMarkingAMonthEmptyRemovesItsEntriesTaskAndIsIdempotent(): void
    {
        $year = $this->year();
        $month = $this->pastMonth();

        self::assertSame(200, $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/empty-month", ['month' => $month])[0]);
        self::assertSame(200, $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/empty-month", ['month' => $month])[0]);

        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_month_marks')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_entries')->fetchColumn());
        [, $html] = $this->call('admin', 'GET', '/admin/accounting', [], ['year' => (string) $year]);
        self::assertStringNotContainsString('data-month="' . $month . '"', $html);
    }

    #[Test]
    public function testAnonymousGets401AMusicianGets403AndAMissingCsrfIsRefused(): void
    {
        $year = $this->year();
        $url = "/api/admin/accounting/years/{$year}/empty-month";

        self::assertSame(401, $this->call('anon', 'POST', $url, ['month' => $this->pastMonth()])[0]);
        self::assertSame(403, $this->call('musicien', 'POST', $url, ['month' => $this->pastMonth()])[0]);
        self::assertSame(403, $this->call('admin', 'POST', $url, ['month' => $this->pastMonth()], [], false)[0]);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_month_marks')->fetchColumn());
    }

    #[Test]
    public function testMalformedIdsAreForbiddenUnknownOnesAre404AndBadMonthsAre422(): void
    {
        $year = $this->year();
        foreach (['0', '-1', 'abc', '1e0', '007', '99999999999999999999', '%00'] as $bad) {
            self::assertSame(403, $this->call('admin', 'POST', "/api/admin/accounting/years/{$bad}/empty-month", ['month' => $this->pastMonth()])[0], $bad);
        }
        [$status, $body] = $this->call('admin', 'POST', '/api/admin/accounting/years/99999999/empty-month', ['month' => $this->pastMonth()]);
        self::assertSame(404, $status);
        self::assertStringNotContainsString('99999999', $body);
        foreach (['', 'hier', date('Y-m'), date('Y-m', strtotime('+3 months')), '2025-13'] as $month) {
            [$status, $body] = $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/empty-month", ['month' => $month]);
            self::assertSame(422, $status, $month);
            self::assertArrayHasKey('month', $this->json($body)['fields']);
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_month_marks')->fetchColumn());
    }

    #[Test]
    public function testAClosedYearIs409(): void
    {
        $year = $this->year();
        $revision = (int) $this->pdo->query("SELECT revision FROM accounting_fiscal_years WHERE id = {$year}")->fetchColumn();
        $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/close", ['revision' => $revision]);

        self::assertSame(409, $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/empty-month", ['month' => $this->pastMonth()])[0]);
    }
}
