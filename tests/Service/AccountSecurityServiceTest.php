<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Database\TransactionRunner;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Repository\MysqlPasswordResetRepository;
use App\Repository\MysqlUserRepository;
use App\Tests\Support\FastPasswordHasher;
use App\Security\PasswordPolicy;
use App\Service\AccountSecurityService;
use App\Service\Exception\InvalidResetTokenException;
use App\Service\PasswordResetService;
use App\Tests\RepositoryTestCase;
use App\Tests\Support\RecordingMailer;
use PHPUnit\Framework\Attributes\Test;

final class AccountSecurityServiceTest extends RepositoryTestCase
{
    private MysqlUserRepository $users;
    private MysqlPasswordResetRepository $resets;
    private RecordingMailer $mailer;
    private AccountSecurityService $service;
    private PasswordResetService $resetService;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->resets = new MysqlPasswordResetRepository($this->pdo);
        $this->mailer = new RecordingMailer();
        $this->now = new \DateTimeImmutable('2026-10-04 12:00:00');
        $transactions = new TransactionRunner($this->pdo);
        $this->resetService = new PasswordResetService($this->users, $this->resets, new FastPasswordHasher(), new PasswordPolicy(), $this->mailer, $transactions, 'no-reply@rehearsalbox.example', 'https://rehearsalbox.example');
        $this->service = new AccountSecurityService($this->users, $this->resets, $this->mailer, $transactions, $this->resetService, 'no-reply@rehearsalbox.example', 'https://rehearsalbox.example');
    }

    private function insertUser(): User
    {
        return $this->users->save(new User(0, 'alice@rehearsalbox.test', password_hash('mdp-actuel', PASSWORD_BCRYPT, ['cost' => 4]), 'Alice', UserRole::Musicien, true, 0, null));
    }

    private function alertToken(User $user): string
    {
        $this->service->sendPasswordChangedAlert($user, $this->now);
        preg_match('/token=([0-9a-f]{64})/', (string) $this->mailer->sent[0]->getTextBody(), $matches);
        $this->mailer->sent = [];

        return $matches[1];
    }

    #[Test]
    public function testSecuringTheAccountLocksItClosesSessionsAndSendsAResetLink(): void
    {
        $user = $this->insertUser();
        $token = $this->alertToken($user);

        $this->service->secureAccount($token, $this->now->modify('+1 hour'));

        $stored = $this->users->findById($user->id());
        self::assertTrue($stored->isLocked($this->now->modify('+1 hour')));
        self::assertSame(1, $stored->sessionVersion());
        self::assertCount(1, $this->mailer->sent);
        self::assertStringContainsString('https://rehearsalbox.example/reset-password?token=', (string) $this->mailer->sent[0]->getTextBody());
    }

    #[Test]
    public function testSecuringTheAccountNeverRestoresAnOldPassword(): void
    {
        $user = $this->insertUser();
        $token = $this->alertToken($user);

        $this->service->secureAccount($token, $this->now);

        self::assertTrue((new FastPasswordHasher())->verify('mdp-actuel', $this->users->findById($user->id())->passwordHash()));
    }

    #[Test]
    public function testTheAlertTokenIsSingleUse(): void
    {
        $user = $this->insertUser();
        $token = $this->alertToken($user);
        $this->service->secureAccount($token, $this->now);

        $this->expectException(InvalidResetTokenException::class);

        $this->service->secureAccount($token, $this->now->modify('+1 minute'));
    }

    #[Test]
    public function testTheAlertTokenExpiresAfter24Hours(): void
    {
        $user = $this->insertUser();
        $token = $this->alertToken($user);

        $this->expectException(InvalidResetTokenException::class);

        $this->service->secureAccount($token, $this->now->modify('+25 hours'));
    }

    #[Test]
    public function testAnUnknownTokenIsRejected(): void
    {
        $this->expectException(InvalidResetTokenException::class);

        $this->service->secureAccount(str_repeat('a', 64), $this->now);
    }

    #[Test]
    public function testAPasswordResetTokenCannotSecureAnAccount(): void
    {
        $user = $this->insertUser();
        $this->resetService->requestReset($user->email(), $this->now);
        preg_match('/token=([0-9a-f]{64})/', (string) $this->mailer->sent[0]->getTextBody(), $matches);

        $this->expectException(InvalidResetTokenException::class);

        $this->service->secureAccount($matches[1], $this->now);
    }

    #[Test]
    public function testTheAlertTokenIsStoredHashed(): void
    {
        $user = $this->insertUser();
        $token = $this->alertToken($user);

        self::assertSame(hash('sha256', $token), $this->pdo->query("SELECT token_hash FROM password_resets WHERE purpose = 'alert'")->fetchColumn());
    }

    #[Test]
    public function testTheAlertMailIsMultipartWithTheBrandedHtmlAndThePlainTextVersion(): void
    {
        $user = $this->users->save(new User(0, 'alice@rehearsalbox.test', password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]), 'Alice', UserRole::Musicien, true, 0, null));

        $this->service->sendPasswordChangedAlert($user, new \DateTimeImmutable('2026-10-04 12:00:00'));

        $email = $this->mailer->sent[0];
        self::assertStringContainsString('#B27', (string) $email->getHtmlBody());
        self::assertStringContainsString('Ce n\'est pas moi', (string) $email->getHtmlBody());
        self::assertMatchesRegularExpression('#/account/secure\?token=[0-9a-f]{64}#', (string) $email->getHtmlBody());
        self::assertMatchesRegularExpression('#/account/secure\?token=[0-9a-f]{64}#', (string) $email->getTextBody());
    }
}
