<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Http;

use App\Account\Entity\UserRole;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

/** #362 : la page du journal de bout en bout : droits, filtres hostiles ignorés, texte échappé. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class AccountingPageTest extends RepositoryTestCase
{
    use HttpHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeUser('admin', UserRole::Admin);
        $this->makeUser('musicien', UserRole::Musicien);
    }

    #[Test]
    public function testThePageIsForAdministratorsOnly(): void
    {
        self::assertSame(302, $this->call('anon', 'GET', '/admin/accounting')[0]);
        self::assertSame(403, $this->call('musicien', 'GET', '/admin/accounting')[0]);
        self::assertSame(200, $this->call('admin', 'GET', '/admin/accounting')[0]);
    }

    #[Test]
    public function testHostileFiltersAndUnknownYearAreIgnored(): void
    {
        foreach ([['direction' => "' OR 1=1 --"], ['category' => '<script>'], ['year' => '99999999'], ['year' => 'abc'], ['direction' => ['x']]] as $query) {
            self::assertSame(200, $this->call('admin', 'GET', '/admin/accounting', [], $query)[0], json_encode($query));
        }
    }

    #[Test]
    public function testUserTextIsEscaped(): void
    {
        [, $body] = $this->call('admin', 'POST', '/api/admin/accounting/years', ['label' => '<img src=x onerror=alert(1)>', 'startsOn' => '2025-10-01', 'endsOn' => '2026-09-30', 'openingBalance' => '0']);
        $year = (int) $this->json($body)['id'];
        $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/entries", ['occurredOn' => '2025-11-05', 'label' => '<script>alert(1)</script>', 'amount' => '5', 'direction' => 'recette', 'category' => 'cotisations', 'paymentMethod' => 'virement']);

        [$status, $html] = $this->call('admin', 'GET', '/admin/accounting', [], ['year' => (string) $year]);

        self::assertSame(200, $status);
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
