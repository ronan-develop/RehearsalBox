<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\AccountApiController;
use App\Database\TransactionRunner;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Http\Request;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlPasswordResetRepository;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\Exception\UnauthenticatedException;
use App\Security\NativePasswordHasher;
use App\Security\PasswordPolicy;
use App\Service\AccountSecurityService;
use App\Service\AuthService;
use App\Service\PasswordChangeService;
use App\Service\PasswordResetService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;
use App\Tests\Support\RecordingMailer;
use PHPUnit\Framework\Attributes\Test;

final class AccountApiControllerTest extends RepositoryTestCase
{
    private MysqlUserRepository $users;
    private RecordingMailer $mailer;
    private AuthService $auth;
    private AccountSecurityService $security;
    private AccountApiController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->mailer = new RecordingMailer();
        $this->auth = new AuthService($this->users, new NativePasswordHasher(), new InMemorySession(), new MysqlGroupRepository($this->pdo));
        $resets = new MysqlPasswordResetRepository($this->pdo);
        $transactions = new TransactionRunner($this->pdo);
        $hasher = new NativePasswordHasher();
        $policy = new PasswordPolicy();
        $resetService = new PasswordResetService($this->users, $resets, $hasher, $policy, $this->mailer, $transactions, 'no-reply@rehearsalbox.example', 'https://rehearsalbox.example');
        $this->security = new AccountSecurityService($this->users, $resets, $this->mailer, $transactions, $resetService, 'no-reply@rehearsalbox.example', 'https://rehearsalbox.example');
        $this->controller = new AccountApiController(
            new AuthGuard($this->auth),
            $this->auth,
            new PasswordChangeService($this->users, $hasher, $policy, $this->security),
            $this->security,
        );
    }

    private function insertUser(string $email = 'alice@rehearsalbox.test'): User
    {
        return $this->users->save(new User(0, $email, (new NativePasswordHasher())->hash('ancien-mdp'), 'Utilisateur', UserRole::Musicien, true, 0, null));
    }

    private function post(string $path, array $body): Request
    {
        return new Request('POST', $path, [], $body, []);
    }

    private function change(array $overrides = []): Request
    {
        return $this->post('/api/auth/change-password', $overrides + [
            'currentPassword' => 'ancien-mdp',
            'password' => 'nouveau-mdp',
            'passwordConfirmation' => 'nouveau-mdp',
        ]);
    }

    #[Test]
    public function testChangePasswordRequiresALoggedInUser(): void
    {
        $this->expectException(UnauthenticatedException::class);

        $this->controller->changePassword($this->change());
    }

    #[Test]
    public function testChangePasswordUpdatesThePasswordAndKeepsTheCurrentSessionLoggedIn(): void
    {
        $user = $this->insertUser();
        $this->auth->attempt('alice@rehearsalbox.test', 'ancien-mdp');

        $response = $this->controller->changePassword($this->change());

        self::assertSame(200, $response->statusCode());
        self::assertTrue((new NativePasswordHasher())->verify('nouveau-mdp', $this->users->findById($user->id())->passwordHash()));
        self::assertNotNull($this->auth->currentUser());
    }

    #[Test]
    public function testChangePasswordAlwaysTargetsTheSessionUserNeverAnIdFromTheRequest(): void
    {
        $alice = $this->insertUser('alice@rehearsalbox.test');
        $bob = $this->insertUser('bob@rehearsalbox.test');
        $this->auth->attempt('alice@rehearsalbox.test', 'ancien-mdp');

        $this->controller->changePassword($this->change(['userId' => $bob->id(), 'id' => $bob->id(), 'email' => 'bob@rehearsalbox.test']));

        self::assertTrue((new NativePasswordHasher())->verify('ancien-mdp', $this->users->findById($bob->id())->passwordHash()));
        self::assertTrue((new NativePasswordHasher())->verify('nouveau-mdp', $this->users->findById($alice->id())->passwordHash()));
    }

    #[Test]
    public function testChangePasswordWithAWrongCurrentPasswordReturns422(): void
    {
        $this->insertUser();
        $this->auth->attempt('alice@rehearsalbox.test', 'ancien-mdp');

        $response = $this->controller->changePassword($this->change(['currentPassword' => 'faux']));

        self::assertSame(422, $response->statusCode());
        self::assertArrayHasKey('currentPassword', json_decode($response->body(), true)['fields']);
    }

    #[Test]
    public function testChangePasswordWithAWeakNewPasswordReturns422(): void
    {
        $this->insertUser();
        $this->auth->attempt('alice@rehearsalbox.test', 'ancien-mdp');

        $response = $this->controller->changePassword($this->change(['password' => 'court', 'passwordConfirmation' => 'court']));

        self::assertSame(422, $response->statusCode());
        self::assertArrayHasKey('password', json_decode($response->body(), true)['fields']);
    }

    #[Test]
    public function testSecureAccountWithAValidAlertTokenReturns200AndLocksTheAccount(): void
    {
        $user = $this->insertUser();
        $this->security->sendPasswordChangedAlert($user);
        preg_match('/token=([0-9a-f]{64})/', (string) $this->mailer->sent[0]->getTextBody(), $matches);

        $response = $this->controller->secureAccount($this->post('/api/auth/secure-account', ['token' => $matches[1]]));

        self::assertSame(200, $response->statusCode());
        self::assertTrue($this->users->findById($user->id())->isLocked(new \DateTimeImmutable()));
    }

    #[Test]
    public function testSecureAccountWithAnInvalidTokenReturns422WithoutDetails(): void
    {
        $response = $this->controller->secureAccount($this->post('/api/auth/secure-account', ['token' => str_repeat('a', 64)]));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('invalide ou expiré', $response->body());
    }

    #[Test]
    public function testSecureAccountWithoutTokenReturns422(): void
    {
        self::assertSame(422, $this->controller->secureAccount($this->post('/api/auth/secure-account', []))->statusCode());
    }
}
