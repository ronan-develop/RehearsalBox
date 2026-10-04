<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\PasswordResetApiController;
use App\Database\TransactionRunner;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Http\Request;
use App\Repository\MysqlPasswordResetRepository;
use App\Repository\MysqlUserRepository;
use App\Security\NativePasswordHasher;
use App\Security\PasswordPolicy;
use App\Service\PasswordResetService;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class PasswordResetApiControllerTest extends RepositoryTestCase
{
    private MysqlUserRepository $users;
    private object $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->mailer = new class implements MailerInterface {
            /** @var list<Email> */
            public array $sent = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->sent[] = $message;
            }
        };
    }

    private function controller(): PasswordResetApiController
    {
        return new PasswordResetApiController(new PasswordResetService(
            $this->users,
            new MysqlPasswordResetRepository($this->pdo),
            new NativePasswordHasher(),
            new PasswordPolicy(),
            $this->mailer,
            new TransactionRunner($this->pdo),
            'no-reply@rehearsalbox.example',
            'https://rehearsalbox.example',
        ));
    }

    private function insertUser(): User
    {
        return $this->users->save(new User(0, 'alice@rehearsalbox.test', password_hash('ancien-mdp', PASSWORD_DEFAULT), 'Alice', UserRole::Musicien, true, 0, null));
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
        self::assertTrue((new NativePasswordHasher())->verify('nouveau-mdp', $this->users->findById($user->id())->passwordHash()));
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
}
