<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\PageController;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Http\Request;
use App\Repository\MysqlGroupDocumentRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Repository\MysqlSlotExceptionRepository;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\Exception\UnauthenticatedException;
use App\Security\NativePasswordHasher;
use App\Service\AuthService;
use App\Service\AvailabilityService;
use App\Service\GroupService;
use App\Service\SlotService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;

final class AccountPagesTest extends RepositoryTestCase
{
    private AuthService $auth;
    private MysqlUserRepository $users;

    private function controller(): PageController
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $slotRepository = new MysqlRecurringSlotRepository($this->pdo);
        $exceptionRepository = new MysqlSlotExceptionRepository($this->pdo);
        $this->users = new MysqlUserRepository($this->pdo);
        $session = new InMemorySession();
        $this->auth = new AuthService($this->users, new NativePasswordHasher(), $session, $groupRepository);

        return new PageController(
            new PhpTemplateRenderer(__DIR__ . '/../../templates'),
            new CsrfTokenManager($session),
            new AuthGuard($this->auth),
            new AvailabilityService($exceptionRepository, $groupRepository, $slotRepository),
            $groupRepository,
            new SlotService($slotRepository, $groupRepository, $exceptionRepository),
            new GroupService($groupRepository, $this->users),
            new MysqlGroupDocumentRepository($this->pdo),
        );
    }

    private function logIn(UserRole $role): void
    {
        $this->users->save(new User(0, 'alice@rehearsalbox.test', (new NativePasswordHasher())->hash('password'), 'Alice', $role, true, 0, null));
        $this->auth->attempt('alice@rehearsalbox.test', 'password');
    }

    private function secureRequest(?string $token): Request
    {
        return new Request('GET', '/account/secure', $token === null ? [] : ['token' => $token], [], []);
    }

    #[Test]
    public function testAccountPasswordPageRequiresALoggedInUser(): void
    {
        $controller = $this->controller();

        $this->expectException(UnauthenticatedException::class);

        $controller->accountPassword();
    }

    #[Test]
    public function testAccountPasswordPageShowsTheThreeFieldAsyncForm(): void
    {
        $controller = $this->controller();
        $this->logIn(UserRole::Musicien);

        $response = $controller->accountPassword();

        self::assertSame(200, $response->statusCode());
        $body = $response->body();
        self::assertStringContainsString('data-endpoint="/api/auth/change-password"', $body);
        self::assertStringContainsString('name="csrf-token"', $body);
        self::assertStringContainsString('name="currentPassword"', $body);
        self::assertStringContainsString('autocomplete="current-password"', $body);
        self::assertStringContainsString('name="password"', $body);
        self::assertStringContainsString('name="passwordConfirmation"', $body);
        self::assertSame(2, substr_count($body, 'autocomplete="new-password"'));
    }

    #[Test]
    public function testAccountPageGroupsTheProfileFormAndThePasswordFormOnOnePage(): void
    {
        $controller = $this->controller();
        $this->logIn(UserRole::Musicien);

        $body = $controller->accountPassword()->body();

        self::assertStringContainsString('<title>Mon compte', $body);
        self::assertStringContainsString('Mes informations', $body);
        // Formulaire du nom affiché : PATCH async, prérempli avec le nom actuel, 100 caractères max.
        self::assertStringContainsString('data-endpoint="/api/account/profile"', $body);
        self::assertStringContainsString('data-method="PATCH"', $body);
        self::assertMatchesRegularExpression('/<input[^>]*name="displayName"[^>]*value="' . preg_quote('Alice', '/') . '"[^>]*maxlength="100"|<input[^>]*name="displayName"[^>]*maxlength="100"[^>]*value="' . preg_quote('Alice', '/') . '"/', $body);
        self::assertStringContainsString('data-field-error="displayName"', $body);
        // L'e-mail s'affiche (lecture seule) : on ne le modifie pas ici.
        self::assertStringContainsString('alice@rehearsalbox.test', $body);
        self::assertStringNotContainsString('name="email"', $body);
        // Le formulaire du mot de passe est toujours là.
        self::assertStringContainsString('data-endpoint="/api/auth/change-password"', $body);
    }

    #[Test]
    public function testAccountPageEscapesTheCurrentDisplayName(): void
    {
        $controller = $this->controller();
        $this->logIn(UserRole::Musicien);
        $this->users->save($this->users->findByEmail('alice@rehearsalbox.test')->withDisplayName('"><script>alert(1)</script>'));

        $body = $controller->accountPassword()->body();

        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
    }

    #[Test]
    public function testSecureAccountPageAsksForAnExplicitConfirmationAndNeverActsOnGet(): void
    {
        $token = str_repeat('ab', 32);

        $response = $this->controller()->secureAccount($this->secureRequest($token));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('data-endpoint="/api/auth/secure-account"', $response->body());
        self::assertStringContainsString('name="token" value="' . $token . '"', $response->body());
        self::assertStringContainsString('type="submit"', $response->body());
    }

    #[Test]
    public function testSecureAccountPageNeverLeaksTheTokenAndEscapesIt(): void
    {
        $response = $this->controller()->secureAccount($this->secureRequest('"><script>alert(1)</script>'));

        self::assertSame('no-referrer', $response->headers()['Referrer-Policy']);
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        self::assertStringNotContainsString('<script>alert(1)</script>', $response->body());
    }

    #[Test]
    public function testSecureAccountPageWithoutTokenShowsAnInvalidLinkMessage(): void
    {
        $response = $this->controller()->secureAccount($this->secureRequest(null));

        self::assertStringNotContainsString('data-endpoint="/api/auth/secure-account"', $response->body());
        self::assertStringContainsString('href="/login"', $response->body());
    }

    #[Test]
    public function testTheNavigationOffersTheChangePasswordPageToAMusician(): void
    {
        $controller = $this->controller();
        $this->logIn(UserRole::Musicien);

        self::assertStringContainsString('href="/account/password"', $controller->dashboard()->body());
    }

    #[Test]
    public function testTheNavigationOffersTheChangePasswordPageToAnAdmin(): void
    {
        $controller = $this->controller();
        $this->logIn(UserRole::Admin);

        self::assertStringContainsString('href="/account/password"', $controller->dashboard()->body());
    }
}
