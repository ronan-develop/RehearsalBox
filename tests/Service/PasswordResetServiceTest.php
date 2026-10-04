<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Database\TransactionRunner;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Repository\MysqlPasswordResetRepository;
use App\Repository\MysqlUserRepository;
use App\Security\NativePasswordHasher;
use App\Security\PasswordPolicy;
use App\Service\Exception\InvalidResetTokenException;
use App\Service\Exception\UserValidationException;
use App\Service\PasswordResetService;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class PasswordResetServiceTest extends RepositoryTestCase
{
    private MysqlUserRepository $users;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->now = new \DateTimeImmutable('2026-10-04 12:00:00');
    }

    private function insertUser(string $email = 'alice@rehearsalbox.test', bool $active = true): User
    {
        return $this->users->save(new User(
            id: 0,
            email: $email,
            passwordHash: password_hash('ancien-mdp', PASSWORD_DEFAULT),
            displayName: 'Alice',
            role: UserRole::Musicien,
            isActive: $active,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
    }

    private function recordingMailer(): object
    {
        return new class implements MailerInterface {
            /** @var list<Email> */
            public array $sent = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->sent[] = $message;
            }
        };
    }

    private function failingMailer(): MailerInterface
    {
        return new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('SMTP indisponible');
            }
        };
    }

    private function service(MailerInterface $mailer): PasswordResetService
    {
        return new PasswordResetService(
            $this->users,
            new MysqlPasswordResetRepository($this->pdo),
            new NativePasswordHasher(),
            new PasswordPolicy(),
            $mailer,
            new TransactionRunner($this->pdo),
            'no-reply@rehearsalbox.example',
            'https://rehearsalbox.example',
        );
    }

    private function tokenFrom(Email $email): string
    {
        self::assertSame(1, preg_match('/token=([0-9a-f]{64})/', (string) $email->getTextBody(), $matches));

        return $matches[1];
    }

    #[Test]
    public function testRequestResetSendsAMailWithAResetLinkToTheUser(): void
    {
        $this->insertUser();
        $mailer = $this->recordingMailer();

        $this->service($mailer)->requestReset('alice@rehearsalbox.test', $this->now);

        self::assertCount(1, $mailer->sent);
        $email = $mailer->sent[0];
        self::assertSame(['alice@rehearsalbox.test'], array_map(static fn ($a) => $a->getAddress(), $email->getTo()));
        self::assertSame(['no-reply@rehearsalbox.example'], array_map(static fn ($a) => $a->getAddress(), $email->getFrom()));
        self::assertStringContainsString('https://rehearsalbox.example/reset-password?token=', (string) $email->getTextBody());
        self::assertStringContainsString('1 heure', (string) $email->getTextBody());
    }

    #[Test]
    public function testTheTokenIsStoredHashedNeverInClear(): void
    {
        $this->insertUser();
        $mailer = $this->recordingMailer();

        $this->service($mailer)->requestReset('alice@rehearsalbox.test', $this->now);

        $token = $this->tokenFrom($mailer->sent[0]);
        $stored = $this->pdo->query('SELECT token_hash FROM password_resets')->fetchColumn();
        self::assertNotSame($token, $stored);
        self::assertSame(hash('sha256', $token), $stored);
    }

    #[Test]
    public function testRequestResetForAnUnknownEmailDoesNothingAndDoesNotFail(): void
    {
        $mailer = $this->recordingMailer();

        $this->service($mailer)->requestReset('inconnu@rehearsalbox.test', $this->now);

        self::assertCount(0, $mailer->sent);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn());
    }

    #[Test]
    public function testRequestResetForAnInactiveAccountSendsNothing(): void
    {
        $this->insertUser(active: false);
        $mailer = $this->recordingMailer();

        $this->service($mailer)->requestReset('alice@rehearsalbox.test', $this->now);

        self::assertCount(0, $mailer->sent);
    }

    #[Test]
    public function testRequestResetIsLimitedToThreePerHourAndPerAccount(): void
    {
        $this->insertUser();
        $this->insertUser('bob@rehearsalbox.test');
        $mailer = $this->recordingMailer();
        $service = $this->service($mailer);

        for ($i = 0; $i < 5; $i++) {
            $service->requestReset('alice@rehearsalbox.test', $this->now->modify("+{$i} minutes"));
        }
        $service->requestReset('bob@rehearsalbox.test', $this->now->modify('+6 minutes'));

        $toAlice = array_filter($mailer->sent, static fn (Email $e) => $e->getTo()[0]->getAddress() === 'alice@rehearsalbox.test');
        self::assertCount(3, $toAlice);
        self::assertCount(4, $mailer->sent);
    }

    #[Test]
    public function testRequestResetIsAllowedAgainOnceTheHourHasPassed(): void
    {
        $this->insertUser();
        $mailer = $this->recordingMailer();
        $service = $this->service($mailer);

        for ($i = 0; $i < 3; $i++) {
            $service->requestReset('alice@rehearsalbox.test', $this->now->modify("+{$i} minutes"));
        }
        $service->requestReset('alice@rehearsalbox.test', $this->now->modify('+2 hours'));

        self::assertCount(4, $mailer->sent);
    }

    #[Test]
    public function testANewRequestInvalidatesThePreviousToken(): void
    {
        $this->insertUser();
        $mailer = $this->recordingMailer();
        $service = $this->service($mailer);

        $service->requestReset('alice@rehearsalbox.test', $this->now);
        $service->requestReset('alice@rehearsalbox.test', $this->now->modify('+1 minute'));

        $this->expectException(InvalidResetTokenException::class);

        $service->resetPassword($this->tokenFrom($mailer->sent[0]), 'nouveau-mdp', $this->now->modify('+2 minutes'));
    }

    #[Test]
    public function testAMailFailureLeavesNoValidTokenAndDoesNotReveal(): void
    {
        $this->insertUser();

        $this->service($this->failingMailer())->requestReset('alice@rehearsalbox.test', $this->now);

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM password_resets WHERE used_at IS NULL')->fetchColumn());
    }

    #[Test]
    public function testResetPasswordChangesThePasswordAndClearsTheLock(): void
    {
        $user = $this->insertUser();
        $this->users->save($this->users->findById($user->id())->withFailedLoginAttempt(1, $this->now, '+15 minutes'));
        $mailer = $this->recordingMailer();
        $service = $this->service($mailer);
        $service->requestReset('alice@rehearsalbox.test', $this->now);

        $service->resetPassword($this->tokenFrom($mailer->sent[0]), 'nouveau-mdp', $this->now->modify('+5 minutes'));

        $updated = $this->users->findById($user->id());
        self::assertTrue((new NativePasswordHasher())->verify('nouveau-mdp', $updated->passwordHash()));
        self::assertFalse((new NativePasswordHasher())->verify('ancien-mdp', $updated->passwordHash()));
        self::assertSame(0, $updated->failedLoginAttempts());
        self::assertNull($updated->lockedUntil());
    }

    #[Test]
    public function testATokenCanOnlyBeUsedOnce(): void
    {
        $this->insertUser();
        $mailer = $this->recordingMailer();
        $service = $this->service($mailer);
        $service->requestReset('alice@rehearsalbox.test', $this->now);
        $token = $this->tokenFrom($mailer->sent[0]);
        $service->resetPassword($token, 'nouveau-mdp', $this->now->modify('+5 minutes'));

        $this->expectException(InvalidResetTokenException::class);

        $service->resetPassword($token, 'autre-mdp-2', $this->now->modify('+6 minutes'));
    }

    #[Test]
    public function testAnExpiredTokenIsRejected(): void
    {
        $this->insertUser();
        $mailer = $this->recordingMailer();
        $service = $this->service($mailer);
        $service->requestReset('alice@rehearsalbox.test', $this->now);

        $this->expectException(InvalidResetTokenException::class);

        $service->resetPassword($this->tokenFrom($mailer->sent[0]), 'nouveau-mdp', $this->now->modify('+61 minutes'));
    }

    #[Test]
    public function testAnUnknownTokenIsRejected(): void
    {
        $this->expectException(InvalidResetTokenException::class);

        $this->service($this->recordingMailer())->resetPassword(str_repeat('a', 64), 'nouveau-mdp', $this->now);
    }

    #[Test]
    public function testAWeakPasswordIsRejectedWithoutConsumingTheToken(): void
    {
        $this->insertUser();
        $mailer = $this->recordingMailer();
        $service = $this->service($mailer);
        $service->requestReset('alice@rehearsalbox.test', $this->now);
        $token = $this->tokenFrom($mailer->sent[0]);

        try {
            $service->resetPassword($token, 'court', $this->now->modify('+1 minute'));
            self::fail('Une UserValidationException était attendue.');
        } catch (UserValidationException $e) {
            self::assertSame(['password'], array_keys($e->fields()));
        }

        // Le jeton reste utilisable avec un mot de passe valide.
        $service->resetPassword($token, 'nouveau-mdp', $this->now->modify('+2 minutes'));
        self::assertTrue((new NativePasswordHasher())->verify(
            'nouveau-mdp',
            $this->users->findByEmail('alice@rehearsalbox.test')->passwordHash(),
        ));
    }

    #[Test]
    public function testResetForAnAccountDeactivatedMeanwhileFailsAndKeepsTheTokenUnused(): void
    {
        $user = $this->insertUser();
        $mailer = $this->recordingMailer();
        $service = $this->service($mailer);
        $service->requestReset('alice@rehearsalbox.test', $this->now);
        $this->pdo->exec('UPDATE users SET is_active = 0 WHERE id = ' . $user->id());

        try {
            $service->resetPassword($this->tokenFrom($mailer->sent[0]), 'nouveau-mdp', $this->now->modify('+1 minute'));
            self::fail('Une InvalidResetTokenException était attendue.');
        } catch (InvalidResetTokenException) {
        }

        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM password_resets WHERE used_at IS NULL')->fetchColumn());
        self::assertTrue((new NativePasswordHasher())->verify('ancien-mdp', $this->users->findById($user->id())->passwordHash()));
    }
}
