<?php

declare(strict_types=1);

namespace App\Tests\Account\Controller\Api;

use App\Account\Controller\Api\PasswordResetApiController;
use App\Database\TransactionRunner;
use App\Account\Entity\UserRole;
use App\Account\Entity\User;
use App\Http\Request;
use App\Account\Repository\MysqlPasswordResetRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Tests\Doubles\FastPasswordHasher;
use App\Account\Security\PasswordPolicy;
use App\Account\Repository\MysqlThrottleEventRepository;
use App\Account\Service\IpThrottle;
use App\Account\Service\PasswordResetService;
use App\Tests\Scenarios\KernelTranslation;
use App\Tests\Doubles\RecordingMailer;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Tests\Scenarios\TestMailbox;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class PasswordResetApiControllerTest extends RepositoryTestCase
{
    private MysqlUserRepository $users;
    private RecordingMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->mailer = new RecordingMailer();
    }

    private const THROTTLE_LIMIT = 3;

    /** Le contrôleur tel que le sert le Kernel : une exception métier devient sa réponse (KernelTranslation). */
    private function controller(): KernelTranslation
    {
        return new KernelTranslation(new PasswordResetApiController(new PasswordResetService(
            $this->users,
            new MysqlPasswordResetRepository($this->pdo),
            new FastPasswordHasher(),
            new PasswordPolicy(),
            TestMailbox::of($this->mailer),
            new TransactionRunner($this->pdo),
        ), new IpThrottle(new MysqlThrottleEventRepository($this->pdo), 'password-reset', self::THROTTLE_LIMIT, '-1 hour')));
    }

    private function insertUser(): User
    {
        return $this->users->save(new User(0, 'alice@rehearsalbox.test', password_hash('ancien-mdp', PASSWORD_BCRYPT, ['cost' => 4]), 'Alice', UserRole::Musicien, true, 0, null));
    }

    private function post(string $path, array $body): Request
    {
        return new Request('POST', $path, [], $body, []);
    }

    private function tokenSent(): string
    {
        preg_match('/token=([0-9a-f]{64})/', (string) $this->mailer->sent[0]->getTextBody(), $matches);

        return $matches[1];
    }

    #[Test]
    public function testForgotPasswordForAKnownEmailReturns200AndSendsAMail(): void
    {
        $this->insertUser();

        $response = $this->controller()->forgotPassword($this->post('/api/auth/forgot-password', ['email' => 'alice@rehearsalbox.test']));

        self::assertSame(200, $response->statusCode());
        self::assertCount(1, $this->mailer->sent);
    }

    #[Test]
    public function testForgotPasswordGivesTheSameResponseForAnUnknownEmail(): void
    {
        $this->insertUser();
        $controller = $this->controller();

        $known = $controller->forgotPassword($this->post('/api/auth/forgot-password', ['email' => 'alice@rehearsalbox.test']));
        $unknown = $controller->forgotPassword($this->post('/api/auth/forgot-password', ['email' => 'inconnu@rehearsalbox.test']));

        self::assertSame($known->statusCode(), $unknown->statusCode());
        self::assertSame($known->body(), $unknown->body());
        self::assertCount(1, $this->mailer->sent);
    }

    #[Test]
    public function testForgotPasswordWithoutEmailReturns422(): void
    {
        $response = $this->controller()->forgotPassword($this->post('/api/auth/forgot-password', []));

        self::assertSame(422, $response->statusCode());
    }

    #[Test]
    public function testResetPasswordWithAValidTokenReturns200AndChangesThePassword(): void
    {
        $user = $this->insertUser();
        $controller = $this->controller();
        $controller->forgotPassword($this->post('/api/auth/forgot-password', ['email' => 'alice@rehearsalbox.test']));

        $response = $controller->resetPassword($this->post('/api/auth/reset-password', ['token' => $this->tokenSent(), 'password' => 'nouveau-mdp']));

        self::assertSame(200, $response->statusCode());
        self::assertTrue((new FastPasswordHasher())->verify('nouveau-mdp', $this->users->findById($user->id())->passwordHash()));
    }

    #[Test]
    public function testResetPasswordWithAnInvalidTokenReturns422WithoutDetails(): void
    {
        $response = $this->controller()->resetPassword($this->post('/api/auth/reset-password', ['token' => str_repeat('a', 64), 'password' => 'nouveau-mdp']));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('invalide ou expiré', $response->body());
    }

    #[Test]
    public function testResetPasswordWithAWeakPasswordReturns422WithThePasswordField(): void
    {
        $this->insertUser();
        $controller = $this->controller();
        $controller->forgotPassword($this->post('/api/auth/forgot-password', ['email' => 'alice@rehearsalbox.test']));

        $response = $controller->resetPassword($this->post('/api/auth/reset-password', ['token' => $this->tokenSent(), 'password' => 'court']));

        self::assertSame(422, $response->statusCode());
        self::assertArrayHasKey('password', json_decode($response->body(), true)['fields']);
    }

    #[Test]
    public function testResetPasswordWithoutTokenReturns422(): void
    {
        $response = $this->controller()->resetPassword($this->post('/api/auth/reset-password', ['password' => 'nouveau-mdp']));

        self::assertSame(422, $response->statusCode());
    }

    private function forgotFrom(KernelTranslation $controller, string $ip, string $email): \App\Http\JsonResponse
    {
        return $controller->forgotPassword(new Request('POST', '/api/auth/forgot-password', [], ['email' => $email], [], [], $ip));
    }

    #[Test]
    public function testAnAddressThatKeepsAskingIsRefusedWithoutAnyWorkDone(): void
    {
        $this->insertUser();
        $controller = $this->controller();
        for ($i = 0; $i < self::THROTTLE_LIMIT; ++$i) {
            self::assertSame(200, $this->forgotFrom($controller, '203.0.113.7', "personne{$i}@rehearsalbox.test")->statusCode());
        }

        $blocked = $this->forgotFrom($controller, '203.0.113.7', 'alice@rehearsalbox.test');

        self::assertSame(429, $blocked->statusCode());
        self::assertSame('3600', $blocked->headers()['Retry-After']);
        self::assertCount(0, $this->mailer->sent, 'une adresse bloquée ne déclenche aucun envoi');
    }

    #[Test]
    public function testAnotherAddressIsNotBlockedAndKnownAndUnknownAccountsGetTheSameAnswer(): void
    {
        $this->insertUser();
        $controller = $this->controller();
        for ($i = 0; $i < self::THROTTLE_LIMIT; ++$i) {
            $this->forgotFrom($controller, '203.0.113.7', "personne{$i}@rehearsalbox.test");
        }

        $known = $this->forgotFrom($controller, '198.51.100.9', 'alice@rehearsalbox.test');
        $unknown = $this->forgotFrom($controller, '198.51.100.9', 'inconnu@rehearsalbox.test');

        self::assertSame(200, $known->statusCode());
        self::assertSame($known->statusCode(), $unknown->statusCode());
        self::assertSame($known->body(), $unknown->body(), 'aucun indice dans la réponse');
    }

    #[Test]
    public function testAnEmptyEmailIsRefusedAndDoesNotCountAgainstTheAddress(): void
    {
        $controller = $this->controller();
        for ($i = 0; $i < self::THROTTLE_LIMIT + 2; ++$i) {
            self::assertSame(422, $this->forgotFrom($controller, '203.0.113.7', '  ')->statusCode());
        }

        self::assertSame(200, $this->forgotFrom($controller, '203.0.113.7', 'inconnu@rehearsalbox.test')->statusCode());
    }
}
