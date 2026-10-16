<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Http;

use App\Account\Entity\UserRole;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/** #362 livraison 3 : clôture et approbation d'un exercice par l'API, de bout en bout (droits, CSRF, identifiants, 409, 422, 404). */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class AccountingYearLifecycleApiTest extends RepositoryTestCase
{
    use HttpHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeUser('admin', UserRole::Admin);
        $this->makeUser('musicien', UserRole::Musicien);
    }

    private function year(string $end = '2025-09-30'): int
    {
        [$status, $body] = $this->call('admin', 'POST', '/api/admin/accounting/years', ['label' => '2024-2025', 'startsOn' => '2024-10-01', 'endsOn' => $end, 'openingBalance' => '100,00']);
        self::assertSame(201, $status, $body);

        return (int) $this->json($body)['id'];
    }

    private function revision(int $year): int
    {
        return (int) $this->pdo->query("SELECT revision FROM accounting_fiscal_years WHERE id = {$year}")->fetchColumn();
    }

    private function statusOf(int $year): string
    {
        return (string) $this->pdo->query("SELECT status FROM accounting_fiscal_years WHERE id = {$year}")->fetchColumn();
    }

    #[Test]
    public function testClosingFreezesTheYearAndKeepsTheClosingBalance(): void
    {
        $year = $this->year();
        $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/entries", ['occurredOn' => '2025-01-05', 'label' => 'Cotisation', 'amount' => '20', 'direction' => 'recette', 'category' => 'cotisations', 'paymentMethod' => 'virement']);

        [$status, $body] = $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/close", ['revision' => $this->revision($year)]);

        self::assertSame(200, $status, $body);
        self::assertSame('cloture', $this->statusOf($year));
        self::assertSame(12000, (int) $this->pdo->query("SELECT closing_balance_cents FROM accounting_fiscal_years WHERE id = {$year}")->fetchColumn());
        [$status] = $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/entries", ['occurredOn' => '2025-02-05', 'label' => 'Tard', 'amount' => '1', 'direction' => 'recette', 'category' => 'cotisations', 'paymentMethod' => 'virement']);
        self::assertSame(409, $status);
    }

    #[Test]
    public function testAStaleRevisionOrASecondClosingIs409AndNothingChanges(): void
    {
        $year = $this->year();
        $revision = $this->revision($year);

        [$status] = $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/close", ['revision' => $revision + 5]);
        self::assertSame(409, $status);
        self::assertSame('ouvert', $this->statusOf($year));

        self::assertSame(200, $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/close", ['revision' => $revision])[0]);
        self::assertSame(409, $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/close", ['revision' => $this->revision($year)])[0]);
    }

    #[Test]
    public function testApprovalNeedsAClosedYearAndAValidPastDate(): void
    {
        $year = $this->year();
        self::assertSame(409, $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/approve", ['approvedOn' => '2025-11-15'])[0], 'exercice encore ouvert');
        $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/close", ['revision' => $this->revision($year)]);

        [$status, $body] = $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/approve", ['approvedOn' => '2025-01-01']);
        self::assertSame(422, $status);
        self::assertArrayHasKey('approvedOn', $this->json($body)['fields']);
        self::assertSame(422, $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/approve", ['approvedOn' => 'demain'])[0]);
        self::assertSame('cloture', $this->statusOf($year));

        self::assertSame(200, $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/approve", ['approvedOn' => '2025-11-15'])[0]);
        self::assertSame('approuve', $this->statusOf($year));
        self::assertSame(409, $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/approve", ['approvedOn' => '2025-11-16'])[0], 'pas deux approbations');
    }

    /** @return iterable<string, array{0: string}> */
    public static function routes(): iterable
    {
        yield 'clôturer' => ['close'];
        yield 'approuver' => ['approve'];
    }

    #[Test]
    #[DataProvider('routes')]
    public function testAnonymousGets401AMusicianGets403AndNothingChanges(string $action): void
    {
        $year = $this->year();
        $body = ['revision' => $this->revision($year), 'approvedOn' => '2025-11-15'];

        self::assertSame(401, $this->call('anon', 'POST', "/api/admin/accounting/years/{$year}/{$action}", $body)[0]);
        self::assertSame(403, $this->call('musicien', 'POST', "/api/admin/accounting/years/{$year}/{$action}", $body)[0]);
        self::assertSame(403, $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/{$action}", $body, [], false)[0], 'sans jeton CSRF');
        self::assertSame('ouvert', $this->statusOf($year));
    }

    #[Test]
    #[DataProvider('routes')]
    public function testMalformedIdsAreForbiddenAndUnknownOnesAre404(string $action): void
    {
        foreach (['0', '-1', 'abc', '1e0', '007', '99999999999999999999', '%00'] as $id) {
            self::assertSame(403, $this->call('admin', 'POST', "/api/admin/accounting/years/{$id}/{$action}", ['revision' => 0, 'approvedOn' => '2025-11-15'])[0], $id);
        }
        [$status, $body] = $this->call('admin', 'POST', "/api/admin/accounting/years/99999999/{$action}", ['revision' => 0, 'approvedOn' => '2025-11-15']);
        self::assertSame(404, $status);
        self::assertStringNotContainsString('99999999', $body);
    }

    #[Test]
    public function testAForgedStatusOrBalanceInTheBodyChangesNothing(): void
    {
        $year = $this->year();

        $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/close", ['revision' => $this->revision($year), 'status' => 'approuve', 'closing_balance_cents' => 999999, 'closingBalance' => '9999']);

        self::assertSame('cloture', $this->statusOf($year));
        self::assertSame(10000, (int) $this->pdo->query("SELECT closing_balance_cents FROM accounting_fiscal_years WHERE id = {$year}")->fetchColumn());
    }
}
