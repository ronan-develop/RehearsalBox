<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Database\TransactionRunner;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Repository\MysqlEmailChangeRepository;
use App\Repository\MysqlUserRepository;
use App\Tests\Support\FastPasswordHasher;
use App\Service\EmailChangeService;
use App\Service\Exception\InvalidEmailChangeException;
use App\Service\Exception\UserValidationException;
use App\Tests\RepositoryTestCase;
use App\Tests\Support\FailingMailer;
use App\Tests\Support\RecordingMailer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class EmailChangeServiceTest extends RepositoryTestCase
{
    private const PASSWORD = 'mon-mot-de-passe-test';

    private MysqlUserRepository $users;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->now = new \DateTimeImmutable('2026-10-04 12:00:00');
    }

    private function user(string $email = 'alice@rehearsalbox.test', bool $active = true): User
    {
        return $this->users->save(new User(0, $email, (new FastPasswordHasher())->hash(self::PASSWORD), 'Alice', UserRole::Musicien, $active, 0, null));
    }

    private function service(MailerInterface $mailer): EmailChangeService
    {
        return new EmailChangeService(
            $this->users,
            new MysqlEmailChangeRepository($this->pdo),
            new FastPasswordHasher(),
            $mailer,
            new TransactionRunner($this->pdo),
            'no-reply@rehearsalbox.example',
            'https://rehearsalbox.example',
        );
    }

    private function tokenFrom(Email $email): string
    {
        self::assertSame(1, preg_match('#/account/email/confirm\?token=([0-9a-f]{64})#', (string) $email->getTextBody(), $matches));

        return $matches[1];
    }

    /** @return list<string> destinataires du message */
    private function recipients(Email $email): array
    {
        return array_map(static fn ($a): string => $a->getAddress(), $email->getTo());
    }

    // --- Demande ------------------------------------------------------------------------------

    #[Test]
    public function testRequestSendsTheConfirmationLinkToTheNewAddressOnlyAndChangesNothingYet(): void
    {
        $alice = $this->user();
        $mailer = new RecordingMailer();

        $this->service($mailer)->requestChange($alice->id(), self::PASSWORD, 'nouvelle@rehearsalbox.test', $this->now);

        self::assertCount(1, $mailer->sent);
        self::assertSame(['nouvelle@rehearsalbox.test'], $this->recipients($mailer->sent[0]));
        self::assertStringContainsString('https://rehearsalbox.example/account/email/confirm?token=', (string) $mailer->sent[0]->getTextBody());
        self::assertSame('alice@rehearsalbox.test', $this->users->findById($alice->id())->email(), "l'adresse ne change qu'après confirmation");
        self::assertNotNull($this->users->findByEmail('alice@rehearsalbox.test'));
        self::assertNull($this->users->findByEmail('nouvelle@rehearsalbox.test'));
    }

    #[Test]
    public function testTheTokenIsStoredHashedNeverInClear(): void
    {
        $alice = $this->user();
        $mailer = new RecordingMailer();

        $this->service($mailer)->requestChange($alice->id(), self::PASSWORD, 'nouvelle@rehearsalbox.test', $this->now);

        $token = $this->tokenFrom($mailer->sent[0]);
        $stored = $this->pdo->query('SELECT token_hash FROM email_changes')->fetchColumn();
        self::assertNotSame($token, $stored);
        self::assertSame(hash('sha256', $token), $stored);
    }

    #[Test]
    public function testAWrongCurrentPasswordIsRefusedCountsAsAFailedAttemptAndSendsNothing(): void
    {
        $alice = $this->user();
        $mailer = new RecordingMailer();

        try {
            $this->service($mailer)->requestChange($alice->id(), 'faux-mot-de-passe', 'nouvelle@rehearsalbox.test', $this->now);
            self::fail('UserValidationException attendue.');
        } catch (UserValidationException $e) {
            self::assertArrayHasKey('currentPassword', $e->fields());
        }

        self::assertSame([], $mailer->sent);
        self::assertSame(1, $this->users->findById($alice->id())->failedLoginAttempts());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM email_changes')->fetchColumn());
    }

    #[Test]
    public function testALockedAccountIsRefusedEvenWithTheRightPassword(): void
    {
        $alice = $this->user();
        $this->users->save($alice->withLockedUntil($this->now->modify('+1 day')));
        $mailer = new RecordingMailer();

        try {
            $this->service($mailer)->requestChange($alice->id(), self::PASSWORD, 'nouvelle@rehearsalbox.test', $this->now);
            self::fail('UserValidationException attendue.');
        } catch (UserValidationException $e) {
            self::assertArrayHasKey('currentPassword', $e->fields());
        }
        self::assertSame([], $mailer->sent);
    }

    #[Test]
    public function testAnInvalidTooLongOrIdenticalAddressIsRefusedOnTheEmailField(): void
    {
        $alice = $this->user();
        $mailer = new RecordingMailer();

        foreach (['pas-un-email', '', str_repeat('a', 185) . '@example.test', 'ALICE@rehearsalbox.test'] as $address) {
            try {
                $this->service($mailer)->requestChange($alice->id(), self::PASSWORD, $address, $this->now);
                self::fail('UserValidationException attendue pour ' . $address);
            } catch (UserValidationException $e) {
                self::assertArrayHasKey('email', $e->fields(), $address);
            }
        }
        self::assertSame([], $mailer->sent);
    }

    #[Test]
    public function testAnAddressAlreadyUsedByAnotherAccountLooksLikeSuccessButSendsNothing(): void
    {
        $alice = $this->user();
        $this->user('bob@rehearsalbox.test');
        $mailer = new RecordingMailer();

        // Aucune exception, aucun mail, aucun jeton : on ne révèle pas quelles adresses ont un compte.
        $this->service($mailer)->requestChange($alice->id(), self::PASSWORD, 'bob@rehearsalbox.test', $this->now);
        $this->service($mailer)->requestChange($alice->id(), self::PASSWORD, 'BOB@rehearsalbox.test', $this->now);

        self::assertSame([], $mailer->sent);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM email_changes')->fetchColumn());
    }

    #[Test]
    public function testRequestsAreLimitedToThreePerHourPerAccount(): void
    {
        $alice = $this->user();
        $mailer = new RecordingMailer();
        $service = $this->service($mailer);

        foreach (['a', 'b', 'c'] as $prefix) {
            $service->requestChange($alice->id(), self::PASSWORD, "{$prefix}@rehearsalbox.test", $this->now);
        }
        try {
            $service->requestChange($alice->id(), self::PASSWORD, 'd@rehearsalbox.test', $this->now);
            self::fail('UserValidationException attendue.');
        } catch (UserValidationException $e) {
            self::assertArrayHasKey('email', $e->fields());
        }
        self::assertCount(3, $mailer->sent);

        $service->requestChange($alice->id(), self::PASSWORD, 'e@rehearsalbox.test', $this->now->modify('+61 minutes'));
        self::assertCount(4, $mailer->sent, 'la limite se libère après une heure');
    }

    #[Test]
    public function testANewRequestReplacesThePreviousOne(): void
    {
        $alice = $this->user();
        $mailer = new RecordingMailer();
        $service = $this->service($mailer);

        $service->requestChange($alice->id(), self::PASSWORD, 'premiere@rehearsalbox.test', $this->now);
        $service->requestChange($alice->id(), self::PASSWORD, 'seconde@rehearsalbox.test', $this->now);

        $this->expectException(InvalidEmailChangeException::class);
        $service->confirm($this->tokenFrom($mailer->sent[0]), $this->now);
    }

    #[Test]
    public function testAMailFailureLeaksNothingAndCancelsTheToken(): void
    {
        $alice = $this->user();

        $this->service(new FailingMailer())->requestChange($alice->id(), self::PASSWORD, 'nouvelle@rehearsalbox.test', $this->now);

        $unused = (int) $this->pdo->query('SELECT COUNT(*) FROM email_changes WHERE used_at IS NULL')->fetchColumn();
        self::assertSame(0, $unused, 'un jeton qui n\'a pas pu être remis est annulé');
    }

    // --- Confirmation ---------------------------------------------------------------------------

    private function requested(string $newEmail = 'nouvelle@rehearsalbox.test'): array
    {
        $alice = $this->user();
        $mailer = new RecordingMailer();
        $service = $this->service($mailer);
        $service->requestChange($alice->id(), self::PASSWORD, $newEmail, $this->now);

        return [$service, $alice, $mailer, $this->tokenFrom($mailer->sent[0])];
    }

    #[Test]
    public function testConfirmChangesTheAddressClosesSessionsAndKeepsThePassword(): void
    {
        [$service, $alice, , $token] = $this->requested();

        $updated = $service->confirm($token, $this->now->modify('+5 minutes'));

        $found = $this->users->findById($alice->id());
        self::assertSame('nouvelle@rehearsalbox.test', $found->email());
        self::assertSame($updated->email(), $found->email());
        self::assertSame($alice->sessionVersion() + 1, $found->sessionVersion());
        self::assertSame($alice->passwordHash(), $found->passwordHash());
        self::assertNull($this->users->findByEmail('alice@rehearsalbox.test'));
    }

    #[Test]
    public function testConfirmAlertsTheOldAddressWithAMaskedNewAddressAndNoLink(): void
    {
        [$service, , $mailer, $token] = $this->requested();

        $service->confirm($token, $this->now->modify('+5 minutes'));

        self::assertCount(2, $mailer->sent);
        $alert = $mailer->sent[1];
        self::assertSame(['alice@rehearsalbox.test'], $this->recipients($alert));
        self::assertStringContainsString('n***@rehearsalbox.test', (string) $alert->getTextBody());
        self::assertStringNotContainsString('nouvelle@rehearsalbox.test', (string) $alert->getTextBody(), 'la nouvelle adresse est masquée');
        self::assertStringNotContainsString('token=', (string) $alert->getTextBody());
    }

    #[Test]
    public function testTheTokenIsSingleUse(): void
    {
        [$service, , , $token] = $this->requested();
        $service->confirm($token, $this->now->modify('+5 minutes'));

        $this->expectException(InvalidEmailChangeException::class);

        $service->confirm($token, $this->now->modify('+6 minutes'));
    }

    #[Test]
    public function testAnExpiredOrUnknownTokenIsRefusedAndChangesNothing(): void
    {
        [$service, $alice, , $token] = $this->requested();

        foreach ([[$token, '+61 minutes'], [str_repeat('0', 64), '+1 minute'], ['', '+1 minute']] as [$candidate, $delay]) {
            try {
                $service->confirm($candidate, $this->now->modify($delay));
                self::fail('InvalidEmailChangeException attendue.');
            } catch (InvalidEmailChangeException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame('alice@rehearsalbox.test', $this->users->findById($alice->id())->email());
    }

    #[Test]
    public function testConfirmIsRefusedIfTheAddressWasTakenInTheMeantimeWithoutRevealingWhy(): void
    {
        [$service, $alice, , $token] = $this->requested('convoitee@rehearsalbox.test');
        $this->user('convoitee@rehearsalbox.test');

        try {
            $service->confirm($token, $this->now->modify('+5 minutes'));
            self::fail('InvalidEmailChangeException attendue.');
        } catch (InvalidEmailChangeException $e) {
            self::assertSame('Ce lien est invalide ou a expiré.', $e->getMessage(), 'même message que pour un jeton expiré');
        }
        self::assertSame('alice@rehearsalbox.test', $this->users->findById($alice->id())->email());
    }

    #[Test]
    public function testConfirmIsRefusedForADeactivatedAccount(): void
    {
        [$service, $alice, , $token] = $this->requested();
        $this->users->save($this->users->findById($alice->id())->withActive(false));

        $this->expectException(InvalidEmailChangeException::class);

        $service->confirm($token, $this->now->modify('+5 minutes'));
    }
}
