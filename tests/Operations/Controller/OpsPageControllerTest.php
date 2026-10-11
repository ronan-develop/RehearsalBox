<?php

declare(strict_types=1);

namespace App\Tests\Operations\Controller;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Service\AuthServiceInterface;
use App\Backup\Restore\BackupCatalog;
use App\Backup\Restore\RestoreStatus;
use App\Http\Request;
use App\Metrics\MetricsAccess;
use App\Metrics\Report\Thresholds;
use App\Operations\Backup\BackupHealthBuilder;
use App\Operations\Backup\Declarations;
use App\Operations\Backup\KeyFileProbe;
use App\Operations\Backup\ProtectionHealthBuilder;
use App\Operations\Controller\OpsPageController;
use App\Operations\OpsPaths;
use App\Security\AuthGuard;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class OpsPageControllerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/rb-OPSCANARY-' . bin2hex(random_bytes(4));
        foreach (['', '/backups', '/shared'] as $dir) {
            mkdir($this->base . $dir, 0o700);
        }
        file_put_contents($this->base . '/backups/db-20261011100000.sql.gz', str_repeat('x', 2048));
        file_put_contents($this->base . '/backups/db-20261011110000<script>.sql.gz', 'x');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base . '/{backups,shared}/*', GLOB_BRACE) ?: [] as $file) {
            unlink($file);
        }
        foreach (['/backups', '/shared', ''] as $dir) {
            rmdir($this->base . $dir);
        }
    }

    private function controller(?User $current, string $viewerEmail = 'owner@rehearsalbox.test'): OpsPageController
    {
        $auth = new class ($current) implements AuthServiceInterface {
            public function __construct(private readonly ?User $user)
            {
            }

            public function attempt(string $email, string $plainPassword): ?User
            {
                return null;
            }

            public function currentUser(): ?User
            {
                return $this->user;
            }

            public function refreshSession(User $user): void
            {
            }

            public function logout(): void
            {
            }

            public function groupsRequiringSelection(): array
            {
                return [];
            }

            public function selectActiveGroup(int $groupId): void
            {
            }
        };
        $clock = new MockClock('2026-10-11 12:00:00 UTC');
        $zone = new \DateTimeZone('Europe/Paris');
        $paths = new OpsPaths($this->base);
        $catalog = new BackupCatalog($this->base . '/backups');

        return new OpsPageController(
            new PhpTemplateRenderer(__DIR__ . '/../../../templates'),
            new AuthGuard($auth),
            new MetricsAccess($viewerEmail),
            new BackupHealthBuilder($catalog, new RestoreStatus($this->base . '/shared', $clock), null, null, new Thresholds(), $clock, $zone),
            new ProtectionHealthBuilder(new KeyFileProbe($this->base . '/shared/keys.json'), new Declarations($paths->declarations(), $clock), $paths, $catalog, new Thresholds(), $clock, $zone),
            $zone,
        );
    }

    private function user(string $email, UserRole $role = UserRole::Admin, bool $active = true): User
    {
        return new User(1, $email, 'hash', 'Test', $role, $active, 0, null);
    }

    private function get(OpsPageController $controller): \App\Http\Response
    {
        return $controller->backups(new Request('GET', '/admin/ops', [], [], []));
    }

    #[Test]
    public function testTheOwnerSeesTheCardsTheChartAndTheLatestDumps(): void
    {
        $response = $this->get($this->controller($this->user('owner@rehearsalbox.test')));
        $body = $response->body();

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('<h1>Exploitation</h1>', $body);
        self::assertStringContainsString('Dernier dump quotidien', $body);
        self::assertStringContainsString('Clé de chiffrement', $body);
        self::assertStringContainsString('<figure class="rb-chart">', $body);
        self::assertStringContainsString('Quotidien', $body);
        self::assertStringContainsString('noindex', $body);
    }

    #[Test]
    public function testThePageOffersABackButtonToTheAvailabilities(): void
    {
        $body = $this->get($this->controller($this->user('owner@rehearsalbox.test')))->body();

        self::assertStringContainsString('<a href="/" class="rb-back-link" aria-label="Retour aux disponibilités">', $body);
    }

    #[Test]
    public function testNoServerPathNorForgedFileNameLeaksIntoThePage(): void
    {
        $body = $this->get($this->controller($this->user('owner@rehearsalbox.test')))->body();

        self::assertStringNotContainsString('OPSCANARY', $body);
        self::assertStringNotContainsString('<script>', $body);
        self::assertStringNotContainsString($this->base, $body);
    }

    #[Test]
    public function testEveryoneElseGetsTheSameNotFound(): void
    {
        $unknown = $this->get($this->controller($this->user('owner@rehearsalbox.test'), ''));
        $cases = [
            'visiteur' => $this->controller(null),
            'musicien' => $this->controller($this->user('owner@rehearsalbox.test', UserRole::Musicien)),
            'autre admin' => $this->controller($this->user('other@rehearsalbox.test')),
            'propriétaire désactivé' => $this->controller($this->user('owner@rehearsalbox.test', UserRole::Admin, false)),
            'adresse non configurée' => $this->controller($this->user('owner@rehearsalbox.test'), ''),
        ];

        foreach ($cases as $name => $controller) {
            $response = $this->get($controller);
            self::assertSame(404, $response->statusCode(), $name);
            self::assertSame($unknown->body(), $response->body(), $name);
            self::assertStringNotContainsString('Exploitation', $response->body(), $name);
        }
    }
}
