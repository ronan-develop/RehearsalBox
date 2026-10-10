<?php

declare(strict_types=1);

namespace App\Tests\Account\Service;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Exception\UserAdminRuleException;
use App\Account\Exception\UserNotFoundException;
use App\Account\Exception\UserValidationException;
use App\Account\Repository\MysqlEmailChangeRepository;
use App\Account\Repository\MysqlPasswordResetRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Account\Security\DisplayNamePolicy;
use App\Account\Security\LastAdminGuard;
use App\Account\Security\ResetToken;
use App\Account\Service\EmailChangeService;
use App\Account\Service\UserAccountAdminService;
use App\Database\TransactionRunner;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\FailingMailer;
use App\Tests\Doubles\FastPasswordHasher;
use App\Tests\Doubles\RecordingLogger;
use App\Tests\Doubles\RecordingMailer;
use App\Tests\Scenarios\TestMailbox;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\MailerInterface;

/** #272 : un administrateur corrige le nom et l'adresse d'un compte et change son rôle, sans jamais contourner les gardes ni fuiter une adresse. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class UserAccountAdminServiceTest extends RepositoryTestCase
{
    private MysqlUserRepository $users;
    private RecordingLogger $logger;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->logger = new RecordingLogger();
        $this->now = new \DateTimeImmutable('2026-10-12 10:00:00');
    }

    private function user(string $name, UserRole $role = UserRole::Musicien, bool $active = true): User
    {
        return $this->users->save(new User(0, "{$name}@rehearsalbox.test", 'h', ucfirst($name), $role, $active, 0, null));
    }

    private function service(MailerInterface $mailer): UserAccountAdminService
    {
        $transactions = new TransactionRunner($this->pdo);

        return new UserAccountAdminService(
            $this->users,
            new EmailChangeService($this->users, new MysqlEmailChangeRepository($this->pdo), new MysqlPasswordResetRepository($this->pdo), new FastPasswordHasher(), TestMailbox::of($mailer), $transactions),
            new LastAdminGuard($this->users),
            new DisplayNamePolicy(),
            $transactions,
            $this->logger,
        );
    }

    // --- Identité --------------------------------------------------------------------------------

    #[Test]
    public function testTheAdminChangesNameAndAddressClosesSessionsRevokesLinksAndAlertsTheOldAddressAfterTheCommit(): void
    {
        $admin = $this->user('admin', UserRole::Admin);
        $alice = $this->user('alice');
        $resets = new MysqlPasswordResetRepository($this->pdo);
        $resets->create($alice->id(), ResetToken::hash('lien-en-attente'), $this->now->modify('+1 hour'), $this->now);
        $mailer = new RecordingMailer();

        $updated = $this->service($mailer)->updateIdentity($alice->id(), '  Alice Martin ', 'nouvelle@rehearsalbox.test', $admin->id(), $this->now);

        self::assertSame('Alice Martin', $updated->displayName());
        self::assertSame('nouvelle@rehearsalbox.test', $updated->email());
        self::assertSame($alice->sessionVersion() + 1, $this->users->findById($alice->id())->sessionVersion(), 'toutes les sessions sont fermées');
        self::assertNull($resets->consume(ResetToken::hash('lien-en-attente'), $this->now), 'les liens envoyés à l\'ancienne boîte ne valent plus');
        self::assertCount(1, $mailer->sent);
        self::assertSame('alice@rehearsalbox.test', $mailer->sent[0]->getTo()[0]->getAddress(), 'l\'alerte part à l\'ANCIENNE adresse');
        self::assertStringContainsString('n***@rehearsalbox.test', (string) $mailer->sent[0]->getTextBody(), 'la nouvelle adresse y est masquée');
        self::assertStringNotContainsString('nouvelle@', (string) $mailer->sent[0]->getTextBody());
    }

    #[Test]
    public function testChangingOnlyTheNameKeepsSessionsAndSendsNoAlert(): void
    {
        $admin = $this->user('admin', UserRole::Admin);
        $alice = $this->user('alice');
        $mailer = new RecordingMailer();

        $updated = $this->service($mailer)->updateIdentity($alice->id(), 'Alice M.', 'alice@rehearsalbox.test', $admin->id(), $this->now);

        self::assertSame('Alice M.', $updated->displayName());
        self::assertSame($alice->sessionVersion(), $this->users->findById($alice->id())->sessionVersion());
        self::assertSame([], $mailer->sent);
    }

    #[Test]
    public function testAnAddressAlreadyUsedRefusesEverythingAndTheNameIsNotWrittenEither(): void
    {
        $admin = $this->user('admin', UserRole::Admin);
        $alice = $this->user('alice');
        $this->user('bob');
        $mailer = new RecordingMailer();

        try {
            $this->service($mailer)->updateIdentity($alice->id(), 'Nouveau nom', 'bob@rehearsalbox.test', $admin->id(), $this->now);
            self::fail('Refus attendu');
        } catch (UserValidationException $e) {
            self::assertSame(['email' => 'Adresse déjà utilisée par un autre compte.'], $e->fields());
        }

        $after = $this->users->findById($alice->id());
        self::assertSame('Alice', $after->displayName(), 'rollback : le nom n\'a pas changé');
        self::assertSame('alice@rehearsalbox.test', $after->email());
        self::assertSame([], $mailer->sent);
    }

    #[Test]
    public function testAnInvalidAddressOrNameIsRefusedBeforeAnyWrite(): void
    {
        $admin = $this->user('admin', UserRole::Admin);
        $alice = $this->user('alice');

        foreach ([['Alice', 'pas-une-adresse', 'email'], ['', 'alice@rehearsalbox.test', 'displayName'], [str_repeat('x', 101), 'alice@rehearsalbox.test', 'displayName'], ["Al\u{202E}ice", 'alice@rehearsalbox.test', 'displayName']] as [$name, $email, $field]) {
            try {
                $this->service(new RecordingMailer())->updateIdentity($alice->id(), $name, $email, $admin->id(), $this->now);
                self::fail("Refus attendu pour {$field}");
            } catch (UserValidationException $e) {
                self::assertArrayHasKey($field, $e->fields());
            }
        }

        self::assertSame('Alice', $this->users->findById($alice->id())->displayName());
    }

    #[Test]
    public function testAFailingMailDoesNotUndoTheChange(): void
    {
        $admin = $this->user('admin', UserRole::Admin);
        $alice = $this->user('alice');

        $updated = $this->service(new FailingMailer())->updateIdentity($alice->id(), 'Alice', 'nouvelle@rehearsalbox.test', $admin->id(), $this->now);

        self::assertSame('nouvelle@rehearsalbox.test', $this->users->findById($alice->id())->email());
        self::assertSame('nouvelle@rehearsalbox.test', $updated->email());
    }

    #[Test]
    public function testAnAdminChangesTheirOwnNameButNotTheirOwnAddressFromHere(): void
    {
        $admin = $this->user('admin', UserRole::Admin);
        $service = $this->service(new RecordingMailer());

        $service->updateIdentity($admin->id(), 'Chef', 'admin@rehearsalbox.test', $admin->id(), $this->now);
        self::assertSame('Chef', $this->users->findById($admin->id())->displayName());

        try {
            $service->updateIdentity($admin->id(), 'Chef', 'autre@rehearsalbox.test', $admin->id(), $this->now);
            self::fail('Refus attendu');
        } catch (UserAdminRuleException $e) {
            self::assertSame('Modifiez votre propre adresse depuis Mon compte.', $e->getMessage());
        }
        self::assertSame('admin@rehearsalbox.test', $this->users->findById($admin->id())->email());
    }

    #[Test]
    public function testAnUnknownAccountIsNotFound(): void
    {
        $admin = $this->user('admin', UserRole::Admin);

        $this->expectException(UserNotFoundException::class);
        $this->service(new RecordingMailer())->updateIdentity(999999, 'Nom', 'x@rehearsalbox.test', $admin->id(), $this->now);
    }

    #[Test]
    public function testTheJournalNamesWhoChangedWhatWithoutAnyAddressOrName(): void
    {
        $admin = $this->user('admin', UserRole::Admin);
        $alice = $this->user('alice');

        $this->service(new RecordingMailer())->updateIdentity($alice->id(), 'Alice Martin', 'nouvelle@rehearsalbox.test', $admin->id(), $this->now);

        $text = $this->logger->text();
        self::assertStringContainsString('Administration : compte modifié', $text);
        self::assertStringContainsString('"actor":' . $admin->id(), $text);
        self::assertStringContainsString('"user":' . $alice->id(), $text);
        self::assertStringContainsString('displayName', $text);
        self::assertStringNotContainsString('@', $text, 'aucune adresse, ni ancienne ni nouvelle');
        self::assertStringNotContainsString('Martin', $text);
    }

    // --- Rôle ------------------------------------------------------------------------------------

    #[Test]
    public function testTheAdminPromotesAndDemotesWithoutClosingSessions(): void
    {
        $admin = $this->user('admin', UserRole::Admin);
        $alice = $this->user('alice');
        $service = $this->service(new RecordingMailer());

        $promoted = $service->changeRole($alice->id(), UserRole::Admin, $admin->id());
        self::assertSame(UserRole::Admin, $promoted->role());
        $demoted = $service->changeRole($alice->id(), UserRole::Musicien, $admin->id());
        self::assertSame(UserRole::Musicien, $demoted->role());
        self::assertSame($alice->sessionVersion(), $this->users->findById($alice->id())->sessionVersion());
        self::assertStringContainsString('"role":"musicien"', $this->logger->text());
    }

    #[Test]
    public function testYouCannotDemoteYourselfAndTheLastActiveAdminIsProtected(): void
    {
        $admin = $this->user('admin', UserRole::Admin);
        $service = $this->service(new RecordingMailer());

        try {
            $service->changeRole($admin->id(), UserRole::Musicien, $admin->id());
            self::fail('Refus attendu');
        } catch (UserAdminRuleException $e) {
            self::assertSame('Vous ne pouvez pas rétrograder votre propre compte.', $e->getMessage());
        }

        $inactiveActor = $this->user('ancien', UserRole::Admin, false); // un acteur qui n'est plus administrateur actif ne peut pas vider l'administration
        try {
            $service->changeRole($admin->id(), UserRole::Musicien, $inactiveActor->id());
            self::fail('Refus attendu');
        } catch (UserAdminRuleException $e) {
            self::assertSame('Impossible de rétrograder le dernier administrateur actif.', $e->getMessage());
        }
        self::assertSame(UserRole::Admin, $this->users->findById($admin->id())->role());
    }

    #[Test]
    public function testTheSameRoleChangesNothingAndLogsNothing(): void
    {
        $admin = $this->user('admin', UserRole::Admin);
        $alice = $this->user('alice');

        $this->service(new RecordingMailer())->changeRole($alice->id(), UserRole::Musicien, $admin->id());

        self::assertSame('', $this->logger->text());
    }
}
