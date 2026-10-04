<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\PageController;
use App\Http\Request;
use App\Repository\MysqlGroupDocumentRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Repository\MysqlSlotExceptionRepository;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\NativePasswordHasher;
use App\Service\AuthService;
use App\Service\AvailabilityService;
use App\Service\GroupService;
use App\Service\SlotService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;
use App\View\PhpTemplateRenderer;
use PHPUnit\Framework\Attributes\Test;

final class PasswordResetPagesTest extends RepositoryTestCase
{
    private function controller(): PageController
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $slotRepository = new MysqlRecurringSlotRepository($this->pdo);
        $exceptionRepository = new MysqlSlotExceptionRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);
        $session = new InMemorySession();
        $authService = new AuthService($userRepository, new NativePasswordHasher(), $session, $groupRepository);

        return new PageController(
            new PhpTemplateRenderer(__DIR__ . '/../../templates'),
            new CsrfTokenManager($session),
            new AuthGuard($authService),
            new AvailabilityService($exceptionRepository, $groupRepository, $slotRepository),
            $groupRepository,
            new SlotService($slotRepository, $groupRepository, $exceptionRepository),
            new GroupService($groupRepository, $userRepository),
            new MysqlGroupDocumentRepository($this->pdo),
        );
    }

    private function resetRequest(?string $token): Request
    {
        return new Request('GET', '/reset-password', $token === null ? [] : ['token' => $token], [], []);
    }

    #[Test]
    public function testForgotPasswordPageShowsTheAsyncEmailForm(): void
    {
        $response = $this->controller()->forgotPassword();

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('data-endpoint="/api/auth/forgot-password"', $response->body());
        self::assertStringContainsString('name="csrf-token"', $response->body());
        self::assertStringContainsString('type="email"', $response->body());
        self::assertStringContainsString('href="/login"', $response->body());
    }

    #[Test]
    public function testResetPasswordPageCarriesTheTokenInAHiddenField(): void
    {
        $token = str_repeat('ab', 32);

        $response = $this->controller()->resetPassword($this->resetRequest($token));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('data-endpoint="/api/auth/reset-password"', $response->body());
        self::assertStringContainsString('name="token" value="' . $token . '"', $response->body());
        self::assertStringContainsString('type="password"', $response->body());
    }

    #[Test]
    public function testResetPasswordPageEscapesTheToken(): void
    {
        $response = $this->controller()->resetPassword($this->resetRequest('"><script>alert(1)</script>'));

        self::assertStringNotContainsString('<script>alert(1)</script>', $response->body());
    }

    #[Test]
    public function testResetPasswordPageNeverLeaksTheTokenThroughReferrerOrCache(): void
    {
        $response = $this->controller()->resetPassword($this->resetRequest(str_repeat('ab', 32)));

        self::assertSame('no-referrer', $response->headers()['Referrer-Policy']);
        self::assertSame('no-store', $response->headers()['Cache-Control']);
    }

    #[Test]
    public function testResetPasswordPageWithoutTokenOffersANewRequestInsteadOfTheForm(): void
    {
        $response = $this->controller()->resetPassword($this->resetRequest(null));

        self::assertStringNotContainsString('data-endpoint="/api/auth/reset-password"', $response->body());
        self::assertStringContainsString('href="/forgot-password"', $response->body());
    }

    #[Test]
    public function testLoginPageLinksToThePasswordResetRequest(): void
    {
        $response = $this->controller()->login();

        self::assertStringContainsString('href="/forgot-password"', $response->body());
    }
}
