<?php

declare(strict_types=1);

namespace App\Tests\Backup\Controller;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Exception\UserValidationException;
use App\Account\Repository\MysqlUserRepository;
use App\Account\Service\AuthServiceInterface;
use App\Account\Service\CurrentPasswordVerifier;
use App\Backup\BackupException;
use App\Backup\Controller\RestoreController;
use App\Backup\Restore\BackupCatalog;
use App\Backup\Restore\DocumentOrphanReport;
use App\Backup\Restore\RestoreStatus;
use App\Group\Repository\MysqlGroupDocumentRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Http\Request;
use App\Metrics\MetricsAccess;
use App\Security\AuthGuard;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\FastPasswordHasher;
use App\Tests\Doubles\RecordingLogger;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

/** #241 : seul le propriétaire, avec son mot de passe et le mot de confirmation, peut lancer une restauration de la base. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class RestoreControllerTest extends RepositoryTestCase
{
    private const OWNER = 'owner@rehearsalbox.test';
    private const FILE = 'db-20261019030000.sql.gz';

    private string $dir;
    /** @var list<string> */
    private array $launched = [];
    private RecordingLogger $logger;
    private MysqlUserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->logger = new RecordingLogger();
        $this->dir = sys_get_temp_dir() . '/restore-controller-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/backups', 0o700, true);
        file_put_contents($this->dir . '/backups/' . self::FILE, 'dump');
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->dir . '/state/*') ?: [], glob($this->dir . '/backups/*') ?: []) as $file) {
            unlink($file);
        }
        foreach (['/state', '/backups', ''] as $sub) {
            is_dir($this->dir . $sub) && rmdir($this->dir . $sub);
        }
    }

    private function user(string $email, UserRole $role = UserRole::Admin, bool $active = true): User
    {
        return $this->users->save(new User(0, $email, (new FastPasswordHasher())->hash('le-bon-mot-de-passe'), 'Test', $role, $active, 0, null));
    }

    private function controller(?User $current, ?\Closure $launch = null): RestoreController
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

        return new RestoreController(
            new PhpTemplateRenderer(__DIR__ . '/../../../templates'),
            new AuthGuard($auth),
            new MetricsAccess(self::OWNER),
            new BackupCatalog($this->dir . '/backups'),
            new RestoreStatus($this->dir . '/state', new MockClock('2026-10-20 10:00:00')),
            new DocumentOrphanReport($this->pdo, $this->dir . '/documents'),
            new CurrentPasswordVerifier($this->users, new FastPasswordHasher()),
            $launch ?? function (string $file): void {
                $this->launched[] = $file;
            },
            new MockClock('2026-10-20 10:00:00'),
            $this->logger,
            new \DateTimeZone('Europe/Paris'),
            new \App\Security\CsrfTokenManager(new \App\Tests\Doubles\InMemorySession()),
        );
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): Request
    {
        return new Request('POST', '/api/admin/restore', [], $body, []);
    }

    private function valid(): array
    {
        return ['file' => self::FILE, 'password' => 'le-bon-mot-de-passe', 'confirmation' => 'RESTAURER'];
    }

    #[Test]
    public function testTheOwnerWithPasswordAndConfirmationStartsTheRestorationAndItIsLogged(): void
    {
        $response = $this->controller($this->user(self::OWNER))->start($this->post($this->valid()));

        self::assertSame(202, $response->statusCode());
        self::assertSame([self::FILE], $this->launched);
        self::assertStringContainsString('Restauration de la base demandée.', $this->logger->text());
        self::assertStringNotContainsString('le-bon-mot-de-passe', $this->logger->text());
    }

    #[Test]
    public function testEveryoneElseGets404AndNothingIsLaunched(): void
    {
        foreach ([null, $this->user('autre.admin@rehearsalbox.test'), $this->user('musicien@rehearsalbox.test', UserRole::Musicien), $this->user('inactif@rehearsalbox.test', UserRole::Admin, false)] as $current) {
            self::assertSame(404, $this->controller($current)->start($this->post($this->valid()))->statusCode());
            self::assertSame(404, $this->controller($current)->page(new Request('GET', '/admin/restore', [], [], []))->statusCode());
        }

        self::assertSame([], $this->launched);
    }

    #[Test]
    public function testAnUnknownFileOrATraversalIsRefusedWith422(): void
    {
        $owner = $this->user(self::OWNER);
        foreach (['absent.sql.gz', '../etc/passwd', '', null, 12] as $file) {
            $response = $this->controller($owner)->start($this->post(['file' => $file] + $this->valid()));
            self::assertSame(422, $response->statusCode());
        }

        self::assertSame([], $this->launched);
    }

    #[Test]
    public function testTheConfirmationWordIsRequired(): void
    {
        $owner = $this->user(self::OWNER);
        foreach (['', 'restaurer', 'oui', null] as $word) {
            $response = $this->controller($owner)->start($this->post(['confirmation' => $word] + $this->valid()));
            self::assertSame(422, $response->statusCode());
        }

        self::assertSame([], $this->launched);
    }

    #[Test]
    public function testAWrongPasswordIsRefusedAndCountsAsAFailedAttempt(): void
    {
        $owner = $this->user(self::OWNER);

        try {
            $this->controller($owner)->start($this->post(['password' => 'faux'] + $this->valid()));
            self::fail('UserValidationException attendue.');
        } catch (UserValidationException) {
        }

        self::assertSame([], $this->launched);
        self::assertSame(1, $this->users->findById($owner->id())->failedLoginAttempts());
    }

    #[Test]
    public function testARestorationAlreadyRunningAnswers409(): void
    {
        $other = new RestoreStatus($this->dir . '/state', new MockClock('now'));
        $other->acquire();

        try {
            $response = $this->controller($this->user(self::OWNER))->start($this->post($this->valid()));
        } finally {
            $other->release();
        }

        self::assertSame(409, $response->statusCode());
        self::assertSame([], $this->launched);
    }

    #[Test]
    public function testALaunchFailureIsReportedWithoutDetails(): void
    {
        $launch = static fn (string $file): never => throw new BackupException('Impossible de lancer la restauration depuis le serveur web.');

        $response = $this->controller($this->user(self::OWNER), $launch)->start($this->post($this->valid()));

        self::assertSame(500, $response->statusCode());
    }

    private function page(User $current): string
    {
        $response = $this->controller($current)->page(new Request('GET', '/admin/restore', [], [], []));
        self::assertSame(200, $response->statusCode());

        return $response->body();
    }

    #[Test]
    public function testThePageListsTheBackupsNewestFirstWithOneRestoreButtonEach(): void
    {
        $owner = $this->user(self::OWNER);
        file_put_contents($this->dir . '/backups/pre-20261020090000-avant-restauration.sql.gz', 'dump');

        $html = $this->page($owner);

        self::assertSame(2, substr_count($html, 'data-restore-file="'));
        $safety = strpos($html, 'data-restore-file="pre-20261020090000-avant-restauration.sql.gz"');
        $daily = strpos($html, 'data-restore-file="' . self::FILE . '"');
        self::assertIsInt($safety);
        self::assertIsInt($daily);
        self::assertLessThan($daily, $safety);
        self::assertStringContainsString('Quotidienne', $html);
        self::assertStringContainsString('Avant restauration', $html);
    }

    #[Test]
    public function testTheRestoreButtonsAreDisabledOnlyWhileARestorationHoldsTheLock(): void
    {
        $owner = $this->user(self::OWNER);
        self::assertDoesNotMatchRegularExpression('/data-restore-file="[^"]*"[^>]*\sdisabled/', $this->page($owner));

        $other = new RestoreStatus($this->dir . '/state', new MockClock('now'));
        $other->acquire();
        try {
            $html = $this->page($owner);
        } finally {
            $other->release();
        }

        self::assertMatchesRegularExpression('/<button[^>]*data-restore-file="' . preg_quote(self::FILE, '/') . '"[^>]*\sdisabled/', $html);
    }

    #[Test]
    public function testAFailedRestorationShowsItsMessageEscapedAndTheSafetyBackupToPutBack(): void
    {
        $owner = $this->user(self::OWNER);
        $status = new RestoreStatus($this->dir . '/state', new MockClock('2026-10-20 10:00:00'));
        $status->begin(self::FILE);
        $status->fail("Import interrompu <script>alert(1)</script>\nVérifiez la base.", 'pre-20261020100000-avant-restauration.sql.gz');

        $html = $this->page($owner);

        self::assertStringContainsString('La restauration a échoué', $html);
        self::assertStringContainsString('Import interrompu &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('pre-20261020100000-avant-restauration.sql.gz', $html);
    }

    #[Test]
    public function testARestorationStoppedMidWayWithoutLockIsSaidInterrupted(): void
    {
        $owner = $this->user(self::OWNER);
        (new RestoreStatus($this->dir . '/state', new MockClock('2026-10-20 10:00:00')))->begin(self::FILE);

        self::assertStringContainsString('interrompue', $this->page($owner));
    }

    #[Test]
    public function testTheDocumentsWithoutFileAndTheOrphanFilesAreListed(): void
    {
        $owner = $this->user(self::OWNER);
        $group = (new MysqlGroupRepository($this->pdo))->save(new \App\Group\Entity\Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        (new MysqlGroupDocumentRepository($this->pdo))->save(new \App\Group\Entity\GroupDocument(0, $group->id(), 'fiche.pdf', 'absent.pdf', 'application/pdf', 100, $owner->id()));
        mkdir($this->dir . '/documents', 0o700, true);
        file_put_contents($this->dir . '/documents/orphelin.pdf', 'contenu');

        try {
            $html = $this->page($owner);
        } finally {
            unlink($this->dir . '/documents/orphelin.pdf');
            rmdir($this->dir . '/documents');
        }

        self::assertStringContainsString('fiche.pdf', $html);
        self::assertStringContainsString('groupe n° ' . $group->id(), $html);
        self::assertStringContainsString('orphelin.pdf', $html);
        self::assertStringNotContainsString('Aucune incohérence', $html);
    }

    #[Test]
    public function testWithoutBackupsOrInconsistenciesThePageSaysSo(): void
    {
        $owner = $this->user(self::OWNER);
        unlink($this->dir . '/backups/' . self::FILE);

        $html = $this->page($owner);

        self::assertStringContainsString('Aucune sauvegarde disponible', $html);
        self::assertStringContainsString('Aucune incohérence', $html);
    }

    #[Test]
    public function testThePageCarriesACsrfTokenSoThatTheRestoreRequestIsAccepted(): void
    {
        $response = $this->controller($this->user(self::OWNER))->page(new Request('GET', '/admin/restore', [], [], []));

        self::assertMatchesRegularExpression('/<meta name="csrf-token" content="[^"]{16,}"/', $response->body());
    }
}
