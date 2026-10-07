<?php

declare(strict_types=1);

namespace App\Tests\Group\Controller;

use App\Group\Controller\AdminGroupPageController;
use App\Account\Entity\UserRole;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Repository\MysqlConversationRepository;
use App\Group\Repository\MysqlGroupImpactRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\Exception\AccessDeniedException;
use App\Security\Exception\UnauthenticatedException;
use App\Account\Service\AuthService;
use App\Group\Service\GroupService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;
use App\Tests\Support\FastPasswordHasher;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;

/** Page admin « Groupes » : liste, onglets et confirmation chiffrée avant suppression (#224). */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class AdminGroupPageControllerTest extends RepositoryTestCase
{
    private MysqlUserRepository $users;
    private MysqlGroupRepository $groups;
    private AuthService $auth;
    private AdminGroupPageController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->groups = new MysqlGroupRepository($this->pdo);
        $session = new InMemorySession();
        $this->auth = new AuthService($this->users, new FastPasswordHasher(), $session, $this->groups);
        $this->controller = new AdminGroupPageController(
            new PhpTemplateRenderer(__DIR__ . '/../../../templates'),
            new CsrfTokenManager($session),
            new AuthGuard($this->auth),
            new GroupService($this->groups, $this->users),
            new MysqlGroupImpactRepository($this->pdo),
        );
    }

    private function user(string $email, UserRole $role): User
    {
        return $this->users->save(new User(0, $email, password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]), $email, $role, true, 0, null));
    }

    private function loginAsAdmin(): User
    {
        $admin = $this->user('admin@rehearsalbox.test', UserRole::Admin);
        $this->auth->attempt('admin@rehearsalbox.test', 'password');

        return $admin;
    }

    #[Test]
    public function testAnonymousVisitorIsRedirectedToLoginByTheKernelException(): void
    {
        $this->expectException(UnauthenticatedException::class);

        $this->controller->index();
    }

    #[Test]
    public function testNonAdminIsRefused(): void
    {
        $this->user('musicien@rehearsalbox.test', UserRole::Musicien);
        $this->auth->attempt('musicien@rehearsalbox.test', 'password');

        $this->expectException(AccessDeniedException::class);

        $this->controller->index();
    }

    #[Test]
    public function testThePageHasGroupsAndUsersTabsWithGroupsCurrent(): void
    {
        $this->loginAsAdmin();

        $body = $this->controller->index()->body();

        // Onglets Groupes | Utilisateurs (#155) : l'onglet courant est marqué, l'autre mène à la page Utilisateurs.
        self::assertStringContainsString('class="rb-admin-tabs"', $body);
        self::assertMatchesRegularExpression('/<a href="\/admin\/groups" class="rb-admin-tab" aria-current="page">\s*Groupes\s*<\/a>/u', $body);
        self::assertMatchesRegularExpression('/<a href="\/admin\/users" class="rb-admin-tab">\s*Utilisateurs\s*<\/a>/u', $body);
        self::assertStringNotContainsString('Gérer les utilisateurs', $body, 'plus de petit lien pris pour un « retour »');
        // La barre du bas garde ses 5 entrées (pas de 6e lien qui déborderait sur mobile).
        self::assertSame(5, substr_count($body, 'rb-bottom-nav-link'), 'la navigation du bas reste à 5 entrées');
    }

    #[Test]
    public function testEachDeleteButtonCarriesWhatTheDeletionWouldTakeWithIt(): void
    {
        $admin = $this->loginAsAdmin();
        $alpha = $this->groups->save(new Group(0, 'Alpha', null, null, 'alpha@example.test'));
        $beta = $this->groups->save(new Group(0, 'Beta', null, null, 'beta@example.test'));
        $this->groups->addMember($alpha->id(), $admin->id());
        (new MysqlConversationRepository($this->pdo))->create($alpha->id(), $beta->id(), null, new \DateTimeImmutable('2026-10-04 10:00:00'), $admin->id());

        $body = $this->controller->index()->body();

        self::assertMatchesRegularExpression('/data-delete-group-button[^>]*data-group-id="' . $alpha->id() . '"[^>]*data-members-count="1"[^>]*data-conversations-count="1"[^>]*data-documents-count="0"[^>]*data-requests-count="0"/s', $body);
        self::assertMatchesRegularExpression('/data-delete-group-button[^>]*data-group-id="' . $beta->id() . '"[^>]*data-members-count="0"[^>]*data-conversations-count="1"/s', $body);
    }
}
