<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Http;

use App\Account\Entity\UserRole;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/** #362 livraison 5 : modèles récurrents et échéances de bout en bout : droits, CSRF, identifiants, 422, 404, double clic, champs forgés. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class AccountingRecurringApiTest extends RepositoryTestCase
{
    use HttpHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeUser('admin', UserRole::Admin);
        $this->makeUser('musicien', UserRole::Musicien);
    }

    /** @return array<string, mixed> */
    private function body(array $override = []): array
    {
        return $override + ['label' => 'Loyer du local', 'direction' => 'depense', 'category' => 'loyer', 'paymentMethod' => 'virement', 'amount' => '498,00', 'voucherRef' => 'Virement loyer', 'periodicity' => 'trimestrielle', 'dueDay' => '5', 'firstMonth' => date('Y-m', strtotime('-5 months'))];
    }

    private function template(array $override = []): int
    {
        [$status, $body] = $this->call('admin', 'POST', '/api/admin/accounting/recurring', $this->body($override));
        self::assertSame(201, $status, $body);

        return (int) $this->json($body)['id'];
    }

    private function year(): int
    {
        $start = date('Y-m-01', strtotime('-6 months'));
        $end = date('Y-m-d', strtotime($start . ' +12 months -1 day'));
        [$status, $body] = $this->call('admin', 'POST', '/api/admin/accounting/years', ['label' => 'Courant', 'startsOn' => $start, 'endsOn' => $end, 'openingBalance' => '0']);
        self::assertSame(201, $status, $body);

        return (int) $this->json($body)['id'];
    }

    private function period(): string
    {
        return date('Y-m-01', strtotime('-5 months'));
    }

    private function entries(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_entries')->fetchColumn();
    }

    #[Test]
    public function testAnAdministratorCreatesUpdatesAndConfirmsAnOccurrence(): void
    {
        $this->year();
        $id = $this->template();

        [$status] = $this->call('admin', 'PUT', "/api/admin/accounting/recurring/{$id}", $this->body(['label' => 'Loyer révisé']));
        self::assertSame(200, $status);
        [$status, $body] = $this->call('admin', 'POST', "/api/admin/accounting/recurring/{$id}/confirm", ['period' => $this->period()]);

        self::assertSame(201, $status, $body);
        self::assertSame(1, $this->json($body)['number']);
        self::assertSame('Loyer révisé', (string) $this->pdo->query('SELECT label FROM accounting_entries')->fetchColumn());
        [$status] = $this->call('admin', 'POST', "/api/admin/accounting/recurring/{$id}/confirm", ['period' => $this->period()]);
        self::assertSame(422, $status, 'double clic');
        self::assertSame(1, $this->entries());
    }

    #[Test]
    public function testIgnoringIsRecordedWithoutAnEntry(): void
    {
        $this->year();
        $id = $this->template();

        [$status] = $this->call('admin', 'POST', "/api/admin/accounting/recurring/{$id}/ignore", ['period' => $this->period()]);

        self::assertSame(200, $status);
        self::assertSame(0, $this->entries());
        self::assertSame('ignoree', (string) $this->pdo->query('SELECT status FROM accounting_recurring_occurrences')->fetchColumn());
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function writes(): iterable
    {
        yield 'créer' => ['POST', '/api/admin/accounting/recurring'];
        yield 'modifier' => ['PUT', '/api/admin/accounting/recurring/1'];
        yield 'confirmer' => ['POST', '/api/admin/accounting/recurring/1/confirm'];
        yield 'abandonner' => ['POST', '/api/admin/accounting/recurring/1/ignore'];
    }

    #[Test]
    #[DataProvider('writes')]
    public function testAnonymousGets401AMusicianGets403AndAMissingCsrfIsRefused(string $method, string $path): void
    {
        $this->year();
        $id = $this->template();
        $path = str_replace('/1', "/{$id}", $path);
        $body = $this->body(['period' => $this->period()]);

        self::assertSame(401, $this->call('anon', $method, $path, $body)[0]);
        self::assertSame(403, $this->call('musicien', $method, $path, $body)[0]);
        self::assertSame(403, $this->call('admin', $method, $path, $body, [], false)[0]);
        self::assertSame(0, $this->entries());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_recurring_templates')->fetchColumn());
    }

    #[Test]
    public function testMalformedIdsAreForbiddenAndUnknownOnesAre404WithoutEchoingTheId(): void
    {
        $this->year();
        foreach (['0', '-1', 'abc', '1e0', '007', '99999999999999999999', '%00'] as $id) {
            foreach (['confirm', 'ignore'] as $action) {
                self::assertSame(403, $this->call('admin', 'POST', "/api/admin/accounting/recurring/{$id}/{$action}", ['period' => $this->period()])[0], "{$id} {$action}");
            }
            self::assertSame(403, $this->call('admin', 'PUT', "/api/admin/accounting/recurring/{$id}", $this->body())[0], $id);
        }
        [$status, $body] = $this->call('admin', 'POST', '/api/admin/accounting/recurring/99999999/confirm', ['period' => $this->period()]);
        self::assertSame(404, $status);
        self::assertStringNotContainsString('99999999', $body);
        self::assertSame(404, $this->call('admin', 'PUT', '/api/admin/accounting/recurring/99999999', $this->body())[0]);
    }

    #[Test]
    public function testInvalidInputIs422WithOneMessagePerField(): void
    {
        [$status, $body] = $this->call('admin', 'POST', '/api/admin/accounting/recurring', ['label' => '', 'dueDay' => '40']);

        self::assertSame(422, $status);
        $fields = $this->json($body)['fields'];
        self::assertArrayHasKey('label', $fields);
        self::assertArrayHasKey('dueDay', $fields);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_recurring_templates')->fetchColumn());
    }

    #[Test]
    public function testAFuturePeriodIsRefusedAndForgedFieldsAreIgnored(): void
    {
        $this->year();
        $id = $this->template();

        [$status] = $this->call('admin', 'POST', "/api/admin/accounting/recurring/{$id}/confirm", ['period' => date('Y-m-01', strtotime('+2 years'))]);
        self::assertSame(422, $status);

        $this->call('admin', 'POST', "/api/admin/accounting/recurring/{$id}/confirm", ['period' => $this->period(), 'label' => 'Piraté', 'direction' => 'recette', 'category' => 'cotisations', 'paymentMethod' => 'especes', 'entryNumber' => 99, 'createdBy' => 777]);
        $row = $this->pdo->query('SELECT label, direction, category, payment_method, entry_number FROM accounting_entries')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['label' => 'Loyer du local', 'direction' => 'depense', 'category' => 'loyer', 'payment_method' => 'virement', 'entry_number' => 1], array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, $row));
    }

    #[Test]
    public function testThePageIsForAdministratorsAndEscapesLabels(): void
    {
        $this->year();
        $this->template(['label' => '<script>alert(1)</script>']);

        [$status, $html] = $this->call('admin', 'GET', '/admin/accounting/recurring');

        self::assertSame(200, $status);
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('À confirmer', $html);
        self::assertSame(302, $this->call('anon', 'GET', '/admin/accounting/recurring')[0]);
        self::assertSame(403, $this->call('musicien', 'GET', '/admin/accounting/recurring')[0]);
    }
}
