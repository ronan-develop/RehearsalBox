<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Http;

use App\Account\Entity\UserRole;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/** #362 : l'API de la comptabilité de bout en bout (noyau réel) : droits, CSRF, identifiants, champs forgés, erreurs en 422, 409 et 404. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class AccountingApiTest extends RepositoryTestCase
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
        [$status, $body] = $this->call('admin', 'POST', '/api/admin/accounting/years', ['label' => '2025-2026', 'startsOn' => '2025-10-01', 'endsOn' => '2026-09-30', 'openingBalance' => '100,00']);
        self::assertSame(201, $status, $body);

        return (int) $this->json($body)['id'];
    }

    /** @return array<string, mixed> */
    private function entryBody(array $overrides = []): array
    {
        return $overrides + ['occurredOn' => '2025-11-05', 'label' => 'Cotisation', 'amount' => '20,00', 'direction' => 'recette', 'category' => 'cotisations', 'paymentMethod' => 'virement'];
    }

    #[Test]
    public function testAnAdministratorCreatesAYearThenAnEntryAndGetsItsNumber(): void
    {
        $year = $this->year();

        [$status, $body] = $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/entries", $this->entryBody());

        self::assertSame(201, $status, $body);
        $json = $this->json($body);
        self::assertSame(1, $json['number']);
        self::assertSame(2000, (int) $this->pdo->query("SELECT amount_cents FROM accounting_entries WHERE id = {$json['id']}")->fetchColumn());
        [, $second] = $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/entries", $this->entryBody(['amount' => '1,5']));
        self::assertSame(2, $this->json($second)['number']);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function writes(): iterable
    {
        yield 'créer un exercice' => ['POST', '/api/admin/accounting/years'];
        yield 'saisir une écriture' => ['POST', '/api/admin/accounting/years/1/entries'];
        yield 'annuler une écriture' => ['POST', '/api/admin/accounting/entries/1/reverse'];
    }

    #[Test]
    #[DataProvider('writes')]
    public function testAnonymousGets401AndAMusicianGets403AndNothingIsWritten(string $method, string $path): void
    {
        self::assertSame(401, $this->call('anon', $method, $path, $this->entryBody())[0]);
        self::assertSame(403, $this->call('musicien', $method, $path, $this->entryBody())[0]);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_fiscal_years')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_entries')->fetchColumn());
    }

    #[Test]
    #[DataProvider('writes')]
    public function testAMissingCsrfTokenIsRefusedBeforeAnythingElse(string $method, string $path): void
    {
        $year = $this->year();

        [$status] = $this->call('admin', $method, str_replace('/1/', "/{$year}/", $path), $this->entryBody(), [], false);

        self::assertSame(403, $status);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_entries')->fetchColumn());
    }

    /** @return iterable<string, array{0: string}> */
    public static function badIds(): iterable
    {
        foreach (['0', '-1', 'abc', '1e0', '007', '99999999999999999999', '%00', '1.5', '1 '] as $id) {
            yield "identifiant « {$id} »" => [$id];
        }
    }

    #[Test]
    #[DataProvider('badIds')]
    public function testAMalformedIdentifierIsRefusedLikeAForbiddenAccess(string $id): void
    {
        self::assertSame(403, $this->call('admin', 'POST', "/api/admin/accounting/years/{$id}/entries", $this->entryBody())[0]);
        self::assertSame(403, $this->call('admin', 'POST', "/api/admin/accounting/entries/{$id}/reverse", ['reason' => 'x', 'occurredOn' => '2025-11-06'])[0]);
    }

    #[Test]
    public function testUnknownObjectsAre404WithoutRepeatingTheIdentifier(): void
    {
        [$status, $body] = $this->call('admin', 'POST', '/api/admin/accounting/years/99999999/entries', $this->entryBody());
        self::assertSame(404, $status);
        self::assertStringNotContainsString('99999999', $body);
        [$status] = $this->call('admin', 'POST', '/api/admin/accounting/entries/99999999/reverse', ['reason' => 'x', 'occurredOn' => '2025-11-06']);
        self::assertSame(404, $status);
    }

    #[Test]
    public function testInvalidInputIs422WithOneMessagePerField(): void
    {
        $year = $this->year();

        [$status, $body] = $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/entries", ['label' => '', 'amount' => 'abc']);

        self::assertSame(422, $status);
        $fields = $this->json($body)['fields'];
        self::assertArrayHasKey('label', $fields);
        self::assertArrayHasKey('amount', $fields);
        [$status, $body] = $this->call('admin', 'POST', '/api/admin/accounting/years', ['label' => '', 'startsOn' => 'hier', 'endsOn' => '']);
        self::assertSame(422, $status);
        self::assertArrayHasKey('startsOn', $this->json($body)['fields']);
    }

    #[Test]
    public function testForgedFieldsInTheBodyAreIgnored(): void
    {
        $year = $this->year();

        [$status, $body] = $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/entries", $this->entryBody([
            'entryNumber' => 99, 'entry_number' => 99, 'fiscalYearId' => 12345, 'createdBy' => 777, 'status' => 'cloture', 'reversesEntryId' => 1, 'id' => 5,
        ]));

        self::assertSame(201, $status, $body);
        $row = $this->pdo->query('SELECT entry_number, fiscal_year_id, reverses_entry_id, created_by_user_id FROM accounting_entries')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(1, (int) $row['entry_number']);
        self::assertSame($year, (int) $row['fiscal_year_id']);
        self::assertNull($row['reverses_entry_id']);
        self::assertNotSame(777, (int) $row['created_by_user_id']);
    }

    #[Test]
    public function testAClosedYearAnswers409AndAnEntryIsReversedOnceOnly(): void
    {
        $year = $this->year();
        $entry = (int) $this->json($this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/entries", $this->entryBody())[1])['id'];

        [$status, $body] = $this->call('admin', 'POST', "/api/admin/accounting/entries/{$entry}/reverse", ['reason' => 'Erreur de saisie', 'occurredOn' => '2025-11-06']);
        self::assertSame(201, $status, $body);
        self::assertSame(2, $this->json($body)['number']);
        [$status, $body] = $this->call('admin', 'POST', "/api/admin/accounting/entries/{$entry}/reverse", ['reason' => 'Encore', 'occurredOn' => '2025-11-06']);
        self::assertSame(422, $status);
        self::assertArrayHasKey('entry', $this->json($body)['fields']);

        [, $container] = $this->actor('admin');
        $admin = $container->get(\App\Account\Repository\UserRepositoryInterface::class)->findByEmail('admin@rehearsalbox.test');
        $container->get(\App\Accounting\Service\FiscalYearService::class)->close($admin, $year, 0);

        [$status] = $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/entries", $this->entryBody());
        self::assertSame(409, $status);
    }

    #[Test]
    public function testResponsesAreJsonAndNeverCached(): void
    {
        $year = $this->year();

        [, , $headers] = $this->call('admin', 'POST', "/api/admin/accounting/years/{$year}/entries", $this->entryBody());

        $lower = array_change_key_case($headers, CASE_LOWER);
        self::assertStringContainsString('application/json', $lower['content-type'] ?? '');
        self::assertStringContainsString('no-store', $lower['cache-control'] ?? '');
    }
}
