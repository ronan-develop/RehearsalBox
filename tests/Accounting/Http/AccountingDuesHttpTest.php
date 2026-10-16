<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Http;

use App\Account\Entity\UserRole;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

/** #362 livraison 6 : cotisations par personne de bout en bout : saisie avec adhérent, doublon, dispenses, page du tableau, échappement des noms. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class AccountingDuesHttpTest extends RepositoryTestCase
{
    use HttpHarness;

    private int $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeUser('admin', UserRole::Admin);
        $this->makeUser('musicien', UserRole::Musicien);
        $this->member = $this->makeUser('adherent', UserRole::Musicien)->id();
    }

    private function year(): int
    {
        $start = date('Y-m-01', strtotime('-2 months'));
        [, $body] = $this->call('admin', 'POST', '/api/admin/accounting/years', ['label' => 'Courant', 'startsOn' => $start, 'endsOn' => date('Y-m-d', strtotime($start . ' +12 months -1 day')), 'openingBalance' => '0']);

        return (int) $this->json($body)['id'];
    }

    /** @return array<string, mixed> */
    private function dues(array $override = []): array
    {
        return $override + ['occurredOn' => date('Y-m-d'), 'label' => 'Cotisation', 'amount' => '20,00', 'direction' => 'recette', 'category' => 'cotisations', 'paymentMethod' => 'virement', 'memberUserId' => (string) $this->member, 'periodMonth' => date('Y-m')];
    }

    #[Test]
    public function testRecordingADuesEntryThenTheSameMonthAgainIs422(): void
    {
        $year = $this->year();

        [$status, $body] = $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/entries", $this->dues());
        self::assertSame(201, $status, $body);
        [$status, $body] = $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/entries", $this->dues());

        self::assertSame(422, $status);
        self::assertArrayHasKey('periodMonth', $this->json($body)['fields']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_entries')->fetchColumn());
    }

    #[Test]
    public function testAForgedMemberOrAMemberOnAnotherCategoryIsRefused(): void
    {
        $year = $this->year();

        foreach ([['memberUserId' => '99999999'], ['memberUserId' => 'Jean Dupont'], ['category' => 'subventions']] as $override) {
            [$status, $body] = $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/entries", $this->dues($override));
            self::assertSame(422, $status, json_encode($override));
            self::assertArrayHasKey('memberUserId', $this->json($body)['fields']);
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_entries')->fetchColumn());
    }

    #[Test]
    public function testExemptionsAreForAdministratorsWithCsrfAndAreIdempotent(): void
    {
        $body = ['memberUserId' => (string) $this->member, 'periodMonth' => date('Y-m'), 'exempt' => true];

        self::assertSame(401, $this->call('anon', 'POST', '/api/admin/accounting/cotisations/exemptions', $body)[0]);
        self::assertSame(403, $this->call('musicien', 'POST', '/api/admin/accounting/cotisations/exemptions', $body)[0]);
        self::assertSame(403, $this->call('admin', 'POST', '/api/admin/accounting/cotisations/exemptions', $body, [], false)[0]);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_member_exemptions')->fetchColumn());

        self::assertSame(200, $this->call('admin', 'POST', '/api/admin/accounting/cotisations/exemptions', $body)[0]);
        self::assertSame(200, $this->call('admin', 'POST', '/api/admin/accounting/cotisations/exemptions', $body)[0]);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_member_exemptions')->fetchColumn());
        self::assertSame(200, $this->call('admin', 'POST', '/api/admin/accounting/cotisations/exemptions', ['exempt' => false] + $body)[0]);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_member_exemptions')->fetchColumn());
    }

    #[Test]
    public function testInvalidExemptionsAre422WithTheFieldName(): void
    {
        [$status, $response] = $this->call('admin', 'POST', '/api/admin/accounting/cotisations/exemptions', ['memberUserId' => 'x', 'periodMonth' => '2025-13', 'exempt' => 'peut-être']);

        self::assertSame(422, $status);
        self::assertEqualsCanonicalizing(['memberUserId', 'periodMonth', 'exempt'], array_keys($this->json($response)['fields']));
    }

    #[Test]
    public function testThePageShowsTheTableEscapesNamesAndKeepsTheJournalReadable(): void
    {
        $year = $this->year();
        $this->pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute(['<img src=x onerror=alert(1)>', $this->member]);
        $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/entries", $this->dues());

        [$status, $html] = $this->call('admin', 'GET', '/admin/accounting/cotisations', [], ['year' => (string) $year]);
        self::assertSame(200, $status);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img', $html);
        self::assertStringContainsString('Payée', $html);
        self::assertStringContainsString('À payer', $html);

        [, $journal] = $this->call('admin', 'GET', '/admin/accounting', [], ['year' => (string) $year]);
        self::assertStringContainsString('Cotisation de &lt;img', $journal);
        self::assertStringNotContainsString('<img src=x', $journal);
    }

    #[Test]
    public function testThePageIsForAdministratorsOnly(): void
    {
        self::assertSame(302, $this->call('anon', 'GET', '/admin/accounting/cotisations')[0]);
        self::assertSame(403, $this->call('musicien', 'GET', '/admin/accounting/cotisations')[0]);
        self::assertSame(200, $this->call('admin', 'GET', '/admin/accounting/cotisations')[0]);
        self::assertSame(200, $this->call('admin', 'GET', '/admin/accounting/cotisations', [], ['year' => '99999999'])[0], 'exercice inconnu ignoré');
    }
}
