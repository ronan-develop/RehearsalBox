<?php

declare(strict_types=1);

namespace App\Tests\Account\Controller;

use App\Account\Controller\AdminUserPageController;
use App\Account\Entity\UserRole;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Group\Repository\MysqlGroupRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\Exception\AccessDeniedException;
use App\Security\Exception\UnauthenticatedException;
use App\Tests\Doubles\FastPasswordHasher;
use App\Account\Security\PasswordPolicy;
use App\Account\Service\AuthService;
use App\Group\Service\GroupService;
use App\Account\Service\UserAdminService;
use App\Account\Service\UserProvisioningService;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\InMemorySession;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class AdminUserPageControllerTest extends RepositoryTestCase
{
    private MysqlUserRepository $users;
    private MysqlGroupRepository $groups;
    private AuthService $auth;
    private AdminUserPageController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->groups = new MysqlGroupRepository($this->pdo);
        $hasher = new FastPasswordHasher();
        $session = new InMemorySession();
        $this->auth = new AuthService($this->users, $hasher, $session, $this->groups);
        $this->controller = new AdminUserPageController(
            new PhpTemplateRenderer(__DIR__ . '/../../../templates'),
            new CsrfTokenManager($session),
            new AuthGuard($this->auth),
            new UserAdminService($this->users, $this->groups, new UserProvisioningService($this->users, $hasher, new PasswordPolicy()), \App\Tests\Support\TestLoginThrottle::make($this->pdo)),
            new GroupService($this->groups, $this->users),
        );
    }

    private function user(string $email, string $name, UserRole $role = UserRole::Musicien, bool $active = true): User
    {
        return $this->users->save(new User(0, $email, password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]), $name, $role, $active, 0, null));
    }

    private function loginAsAdmin(): User
    {
        $admin = $this->user('admin@rehearsalbox.test', 'Admin', UserRole::Admin);
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
        $this->user('musicien@rehearsalbox.test', 'Musicien');
        $this->auth->attempt('musicien@rehearsalbox.test', 'password');

        $this->expectException(AccessDeniedException::class);

        $this->controller->index();
    }

    #[Test]
    public function testAdminSeesTheCreateFormWithoutAnyPasswordField(): void
    {
        $this->loginAsAdmin();
        $this->groups->save(new Group(0, 'The Office', null, null, 'c@example.test'));

        $body = $this->controller->index()->body();

        self::assertStringContainsString('endpoint="/api/admin/users"', $body);
        self::assertStringContainsString('name="email"', $body);
        self::assertStringContainsString('name="displayName"', $body);
        self::assertStringContainsString('name="role"', $body);
        self::assertStringContainsString('<option value="musicien"', $body);
        self::assertStringContainsString('<option value="admin"', $body);
        self::assertStringContainsString('name="groupId"', $body);
        self::assertStringContainsString('The Office', $body);
        self::assertStringNotContainsString('type="password"', $body, "l'admin ne choisit aucun mot de passe");
        self::assertStringContainsString('Mot de passe oublié', $body, 'la consigne de première connexion est expliquée');
        self::assertMatchesRegularExpression('/<meta name="csrf-token" content="[0-9a-f]{20,}"/', $body);
    }

    #[Test]
    public function testAdminSeesEveryAccountWithItsStateAndActions(): void
    {
        $admin = $this->loginAsAdmin();
        $alice = $this->user('alice@rehearsalbox.test', 'Alice');
        $bob = $this->user('bob@rehearsalbox.test', 'Bob', UserRole::Musicien, false);
        $carl = $this->user('carl@rehearsalbox.test', 'Carl');
        $this->users->save($carl->withLockedUntil(new \DateTimeImmutable('+7 days')));
        $group = $this->groups->save(new Group(0, 'Rock', null, null, 'c@example.test'));
        $this->groups->addMember($group->id(), $alice->id());

        $body = $this->controller->index()->body();

        foreach (['Alice', 'alice@rehearsalbox.test', 'Bob', 'Carl', 'Rock'] as $expected) {
            self::assertStringContainsString($expected, $body);
        }
        // Désactiver : proposé pour les autres comptes, jamais pour soi-même.
        self::assertStringContainsString('data-deactivate-user-button data-user-id="' . $alice->id() . '"', $body);
        self::assertStringNotContainsString('data-deactivate-user-button data-user-id="' . $admin->id() . '"', $body);
        // Compte désactivé : badge + réactivation ; verrouillé : badge + déblocage.
        self::assertStringContainsString('data-activate-user-button data-user-id="' . $bob->id() . '"', $body);
        self::assertStringContainsString('Désactivé', $body);
        self::assertStringContainsString('data-unlock-user-button data-user-id="' . $carl->id() . '"', $body);
        self::assertStringContainsString('Verrouillé', $body);
        self::assertStringNotContainsString('data-unlock-user-button data-user-id="' . $alice->id() . '"', $body);
    }

    #[Test]
    public function testUserSuppliedTextIsEscaped(): void
    {
        $this->loginAsAdmin();
        $this->user('evil@rehearsalbox.test', '<script>alert(1)</script>');
        $this->groups->save(new Group(0, '"><img src=x onerror=alert(2)>', null, null, 'c@example.test'));

        $body = $this->controller->index()->body();

        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringNotContainsString('<img src=x onerror', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
    }

    #[Test]
    public function testPageHasGroupsAndUsersTabsWithUsersCurrentAndShowsTheAdminNavigation(): void
    {
        $this->loginAsAdmin();

        $body = $this->controller->index()->body();

        self::assertStringContainsString('class="rb-admin-tabs"', $body);
        self::assertMatchesRegularExpression('/<a href="\/admin\/groups" class="rb-admin-tab">\s*Groupes\s*<\/a>/u', $body);
        self::assertMatchesRegularExpression('/<a href="\/admin\/users" class="rb-admin-tab" aria-current="page">\s*Utilisateurs\s*<\/a>/u', $body);
        self::assertStringNotContainsString('← Groupes', $body, 'plus de lien « retour » : les onglets suffisent');
        self::assertStringContainsString('rb-bottom-nav', $body);
    }
}
