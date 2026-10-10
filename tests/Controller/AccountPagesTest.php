<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\PageController;
use App\Account\Entity\UserRole;
use App\Account\Entity\User;
use App\Http\Request;
use App\Group\Repository\MysqlGroupDocumentRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Planning\Repository\MysqlRecurringSlotRepository;
use App\Planning\Repository\MysqlSlotExceptionRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\Exception\UnauthenticatedException;
use App\Tests\Doubles\FastPasswordHasher;
use App\Account\Service\AuthService;
use App\Planning\Service\AvailabilityService;
use App\Group\Service\GroupService;
use App\Planning\Service\SlotService;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\InMemorySession;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use App\Tests\Scenarios\TestDashboard;

#[\PHPUnit\Framework\Attributes\Group('db')]
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
        $this->auth = new AuthService($this->users, new FastPasswordHasher(), $session, $groupRepository);

        return new PageController(
            new PhpTemplateRenderer(__DIR__ . '/../../templates'),
            new CsrfTokenManager($session),
            new AuthGuard($this->auth),
            $groupRepository,
            new SlotService($slotRepository, $groupRepository, $exceptionRepository),
            new GroupService($groupRepository, $this->users),
            new MysqlGroupDocumentRepository($this->pdo),
            TestDashboard::view($this->pdo),
        );
    }

    private function logIn(UserRole $role): void
    {
        $this->users->save(new User(0, 'alice@rehearsalbox.test', (new FastPasswordHasher())->hash('password'), 'Alice', $role, true, 0, null));
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
    public function testAccountPageOffersNoGeneralEmailUnsubscribe(): void
    {
        $controller = $this->controller();
        $this->logIn(UserRole::Musicien);

        $page = $controller->accountPassword()->body();

        // Règle du projet (#266) : on ne se désabonne que par conversation (sourdine), jamais en général.
        self::assertStringNotContainsString('/api/account/notifications', $page);
        self::assertStringNotContainsString('emailNotifications', $page);
        self::assertStringNotContainsString('Notifications par e-mail', $page);
    }

    #[Test]
    public function testAccountPasswordPageShowsTheThreeFieldAsyncForm(): void
    {
        $controller = $this->controller();
        $this->logIn(UserRole::Musicien);

        $response = $controller->accountPassword();

        self::assertSame(200, $response->statusCode());
        $body = $response->body();
        self::assertStringContainsString('endpoint="/api/auth/change-password"', $body);
        self::assertStringContainsString('name="csrf-token"', $body);
        self::assertStringContainsString('name="currentPassword"', $body);
        self::assertStringContainsString('autocomplete="current-password"', $body);
        self::assertStringContainsString('name="password"', $body);
        self::assertStringContainsString('name="passwordConfirmation"', $body);
        self::assertSame(2, substr_count($body, 'autocomplete="new-password"'));
        self::assertSame(2, substr_count($body, 'minlength="10"'), 'les deux champs du nouveau mot de passe annoncent la règle (#223)');
        self::assertStringContainsString('10 caractères minimum', $body);
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
        self::assertStringContainsString('endpoint="/api/account/profile"', $body);
        self::assertStringContainsString('method="PATCH"', $body);
        self::assertMatchesRegularExpression('/<input[^>]*name="displayName"[^>]*value="' . preg_quote('Alice', '/') . '"[^>]*maxlength="100"|<input[^>]*name="displayName"[^>]*maxlength="100"[^>]*value="' . preg_quote('Alice', '/') . '"/', $body);
        self::assertStringContainsString('data-field-error="displayName"', $body);
        // L'adresse e-mail actuelle s'affiche ; son changement est un formulaire à part (mot de passe actuel requis).
        self::assertStringContainsString('alice@rehearsalbox.test', $body);
        // Le formulaire du mot de passe est toujours là.
        self::assertStringContainsString('endpoint="/api/auth/change-password"', $body);
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
        self::assertStringContainsString('endpoint="/api/auth/secure-account"', $response->body());
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

        self::assertStringNotContainsString('endpoint="/api/auth/secure-account"', $response->body());
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

    // --- Changement d'adresse e-mail (#164) --------------------------------------------------------------

    #[Test]
    public function testAccountPageOffersTheEmailChangeFormAskingForTheCurrentPassword(): void
    {
        $controller = $this->controller();
        $this->logIn(UserRole::Musicien);

        $body = $controller->accountPassword()->body();

        self::assertStringContainsString('endpoint="/api/account/email"', $body);
        self::assertMatchesRegularExpression('/<rb-async-form[^>]*endpoint="\/api\/account\/email"[^>]*method="PATCH"/', $body);
        self::assertMatchesRegularExpression('/<input type="email"[^>]*name="email"/', $body);
        self::assertStringContainsString('data-field-error="email"', $body);
        self::assertSame(1, preg_match_all('/endpoint="\/api\/account\/email"/', $body), 'un seul formulaire e-mail');
        // Le mot de passe actuel est demandé, jamais prérempli.
        self::assertMatchesRegularExpression('/<rb-async-form[^>]*\/api\/account\/email".*?name="currentPassword"[^>]*autocomplete="current-password"/s', $body);
        self::assertStringNotContainsString('value="ancien', $body);
        self::assertStringContainsString('lien de confirmation', $body, "la consigne explique le lien envoyé à la nouvelle adresse");
    }

    private function confirmEmailRequest(?string $token): Request
    {
        return new Request('GET', '/account/email/confirm', $token === null ? [] : ['token' => $token], [], []);
    }

    #[Test]
    public function testConfirmEmailPageIsPublicAndAsksForAnExplicitConfirmationNeverActingOnGet(): void
    {
        $token = str_repeat('ab', 32);
        $controller = $this->controller();

        // Aucune connexion nécessaire : le lien est ouvert depuis la boîte mail (peut-être sur un autre appareil).
        $response = $controller->confirmEmail($this->confirmEmailRequest($token));

        self::assertSame(200, $response->statusCode());
        $body = $response->body();
        self::assertStringContainsString('endpoint="/api/account/email/confirm"', $body);
        self::assertStringContainsString('method="POST"', $body);
        self::assertStringContainsString('<input type="hidden" name="token" value="' . $token . '">', $body);
        self::assertStringContainsString('Confirmer ma nouvelle adresse', $body);
        self::assertStringContainsString('data-confirmation', $body);
    }

    #[Test]
    public function testConfirmEmailPageNeverLeaksTheTokenAndEscapesIt(): void
    {
        $response = $this->controller()->confirmEmail($this->confirmEmailRequest('"><script>alert(1)</script>'));

        self::assertSame('no-referrer', $response->headers()['Referrer-Policy'] ?? null);
        self::assertSame('no-store', $response->headers()['Cache-Control'] ?? null);
        self::assertStringContainsString('<meta name="referrer" content="no-referrer">', $response->body());
        self::assertStringNotContainsString('<script>alert(1)</script>', $response->body());
    }

    #[Test]
    public function testConfirmEmailPageWithoutTokenShowsAnInvalidLinkMessage(): void
    {
        $response = $this->controller()->confirmEmail($this->confirmEmailRequest(null));

        self::assertStringContainsString('invalide ou incomplet', $response->body());
        self::assertStringNotContainsString('<form', $response->body());
    }
}
