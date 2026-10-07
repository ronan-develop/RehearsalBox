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
use App\Service\Exception\UserValidationException;
use App\Service\PasswordChangeService;
use App\Service\PasswordResetService;
use App\Tests\RepositoryTestCase;
use App\Tests\Support\FailingMailer;
use App\Tests\Support\RecordingMailer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\MailerInterface;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class PasswordChangeServiceTest extends RepositoryTestCase
{
    private MysqlUserRepository $users;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->now = new \DateTimeImmutable('2026-10-04 12:00:00');
    }

    private function insertUser(): User
    {
        return $this->users->save(new User(0, 'alice@rehearsalbox.test', password_hash('ancien-mdp', PASSWORD_BCRYPT, ['cost' => 4]), 'Alice', UserRole::Musicien, true, 0, null));
    }

    private function service(MailerInterface $mailer): PasswordChangeService
    {
        $resets = new MysqlPasswordResetRepository($this->pdo);
        $hasher = new FastPasswordHasher();
        $policy = new PasswordPolicy();
        $transactions = new TransactionRunner($this->pdo);
        $resetService = new PasswordResetService($this->users, $resets, $hasher, $policy, \App\Tests\Support\TestMailbox::of($mailer), $transactions);
        $security = new AccountSecurityService($this->users, $resets, \App\Tests\Support\TestMailbox::of($mailer), $transactions, $resetService);

        return new PasswordChangeService($this->users, $hasher, $policy, $security);
    }

    /** @return array<string, string> */
    private function failureFields(callable $action): array
    {
        try {
            $action();
        } catch (UserValidationException $e) {
            return $e->fields();
        }
        self::fail('Une UserValidationException était attendue.');
    }

    #[Test]
    public function testChangePasswordUpdatesThePasswordAndClosesOtherSessions(): void
    {
        $user = $this->insertUser();

        $updated = $this->service(new RecordingMailer())->changePassword($user->id(), 'ancien-mdp', 'nouveau-mdp', 'nouveau-mdp', $this->now);

        $stored = $this->users->findById($user->id());
        self::assertTrue((new FastPasswordHasher())->verify('nouveau-mdp', $stored->passwordHash()));
        self::assertSame(1, $stored->sessionVersion());
        self::assertSame(1, $updated->sessionVersion());
    }

    #[Test]
    public function testAWrongCurrentPasswordIsRefusedAndCountsAsAFailedAttempt(): void
    {
        $user = $this->insertUser();

        $fields = $this->failureFields(fn () => $this->service(new RecordingMailer())->changePassword($user->id(), 'faux', 'nouveau-mdp', 'nouveau-mdp', $this->now));

        self::assertSame(['currentPassword'], array_keys($fields));
        $stored = $this->users->findById($user->id());
        self::assertSame(1, $stored->failedLoginAttempts());
        self::assertTrue((new FastPasswordHasher())->verify('ancien-mdp', $stored->passwordHash()));
    }

    #[Test]
    public function testRepeatedWrongCurrentPasswordsLockTheAccountLikeALogin(): void
    {
        $user = $this->insertUser();
        $service = $this->service(new RecordingMailer());

        for ($i = 0; $i < 5; $i++) {
            $this->failureFields(fn () => $service->changePassword($user->id(), 'faux', 'nouveau-mdp', 'nouveau-mdp', $this->now));
        }

        self::assertTrue($this->users->findById($user->id())->isLocked($this->now));
        // Même avec le bon mot de passe actuel, un compte verrouillé est refusé.
        $fields = $this->failureFields(fn () => $service->changePassword($user->id(), 'ancien-mdp', 'nouveau-mdp', 'nouveau-mdp', $this->now));
        self::assertSame(['currentPassword'], array_keys($fields));
        self::assertTrue((new FastPasswordHasher())->verify('ancien-mdp', $this->users->findById($user->id())->passwordHash()));
    }

    #[Test]
    public function testATooShortNewPasswordIsRefusedWithoutCountingAFailedAttempt(): void
    {
        $user = $this->insertUser();

        $fields = $this->failureFields(fn () => $this->service(new RecordingMailer())->changePassword($user->id(), 'ancien-mdp', 'court', 'court', $this->now));

        self::assertSame(['password'], array_keys($fields));
        self::assertSame(0, $this->users->findById($user->id())->failedLoginAttempts());
    }

    #[Test]
    public function testTheNewPasswordMustDifferFromTheCurrentOne(): void
    {
        $user = $this->insertUser();

        $fields = $this->failureFields(fn () => $this->service(new RecordingMailer())->changePassword($user->id(), 'ancien-mdp', 'ancien-mdp', 'ancien-mdp', $this->now));

        self::assertSame(['password'], array_keys($fields));
    }

    #[Test]
    public function testTheConfirmationMustMatch(): void
    {
        $user = $this->insertUser();

        $fields = $this->failureFields(fn () => $this->service(new RecordingMailer())->changePassword($user->id(), 'ancien-mdp', 'nouveau-mdp', 'autre-chose', $this->now));

        self::assertSame(['passwordConfirmation'], array_keys($fields));
    }

    #[Test]
    public function testEveryInvalidNewPasswordFieldIsReportedAtOnce(): void
    {
        $user = $this->insertUser();

        $fields = $this->failureFields(fn () => $this->service(new RecordingMailer())->changePassword($user->id(), 'ancien-mdp', 'court', 'autre', $this->now));

        self::assertSame(['password', 'passwordConfirmation'], array_keys($fields));
    }

    #[Test]
    public function testASuccessfulChangeSendsAnAlertWithANotMeLink(): void
    {
        $user = $this->insertUser();
        $mailer = new RecordingMailer();

        $this->service($mailer)->changePassword($user->id(), 'ancien-mdp', 'nouveau-mdp', 'nouveau-mdp', $this->now);

        self::assertCount(1, $mailer->sent);
        $body = (string) $mailer->sent[0]->getTextBody();
        self::assertSame(['alice@rehearsalbox.test'], array_map(static fn ($a) => $a->getAddress(), $mailer->sent[0]->getTo()));
        self::assertStringContainsString('https://rehearsalbox.example/account/secure?token=', $body);
        self::assertStringContainsString('24 heures', $body);
        self::assertStringNotContainsString('nouveau-mdp', $body);
        self::assertStringNotContainsString('ancien-mdp', $body);
        self::assertSame('alert', $this->pdo->query('SELECT purpose FROM password_resets')->fetchColumn());
    }

    #[Test]
    public function testAMailFailureDoesNotFailTheChangeNorLeaveAnAlertToken(): void
    {
        $user = $this->insertUser();

        $this->service(new FailingMailer())->changePassword($user->id(), 'ancien-mdp', 'nouveau-mdp', 'nouveau-mdp', $this->now);

        self::assertTrue((new FastPasswordHasher())->verify('nouveau-mdp', $this->users->findById($user->id())->passwordHash()));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM password_resets WHERE used_at IS NULL')->fetchColumn());
    }

    #[Test]
    public function testAnUnknownUserIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service(new RecordingMailer())->changePassword(9999, 'x', 'nouveau-mdp', 'nouveau-mdp', $this->now);
    }
}
