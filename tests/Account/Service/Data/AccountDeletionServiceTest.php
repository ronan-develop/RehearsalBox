<?php

declare(strict_types=1);

namespace App\Tests\Account\Service\Data;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Exception\UserAdminRuleException;
use App\Account\Exception\UserValidationException;
use App\Account\Repository\MysqlEmailChangeRepository;
use App\Account\Repository\MysqlPasswordResetRepository;
use App\Account\Security\ResetToken;
use App\Account\Service\Data\AccountDeletionService;
use App\Account\Service\CurrentPasswordVerifier;
use App\Database\TransactionRunner;
use App\Group\Entity\GroupUserRole;
use App\Group\Exception\LastGroupManagerException;
use App\Group\Repository\MysqlGroupManagerRepository;
use App\Group\Service\GroupManagerService;
use App\Messaging\Repository\MysqlConversationMessageRepository;
use App\Messaging\Repository\MysqlConversationRepository;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\FastPasswordHasher;
use App\Tests\Doubles\RecordingMailer;
use App\Tests\Doubles\ThrowingMailer;
use App\Tests\Scenarios\MessagingScenario;
use App\Tests\Scenarios\TestMailbox;
use App\Tests\Support\TestMessageCipher;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\MailerInterface;

/** #431 : une personne supprime son compte (mot de passe redemandé) : ses données personnelles partent, les conversations des autres restent lisibles. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class AccountDeletionServiceTest extends RepositoryTestCase
{
    use MessagingScenario;

    private const PASSWORD = 'le-bon-mot-de-passe';

    private RecordingMailer $mailer;

    private function service(?MailerInterface $mailer = null): AccountDeletionService
    {
        $this->mailer = new RecordingMailer();
        $hasher = new FastPasswordHasher();

        return new AccountDeletionService(
            $this->users,
            $this->groups,
            new GroupManagerService($this->groups, new MysqlGroupManagerRepository($this->pdo)),
            new CurrentPasswordVerifier($this->users, $hasher),
            new MysqlPasswordResetRepository($this->pdo),
            new MysqlEmailChangeRepository($this->pdo),
            $hasher,
            new TransactionRunner($this->pdo),
            TestMailbox::of($mailer ?? $this->mailer),
            new MockClock('2026-10-20 10:00:00'),
        );
    }

    private function person(string $name, UserRole $role = UserRole::Musicien): User
    {
        return $this->users->save(new User(0, strtolower($name) . '@rehearsalbox.test', (new FastPasswordHasher())->hash(self::PASSWORD), $name, $role, true, 0, null));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
    }

    #[Test]
    public function testThePersonalDataIsErasedAndTheAccountCanNoLongerSignIn(): void
    {
        $alice = $this->person('Alice');
        $this->group('Alpha', $alice);
        $this->users->recordLogin($alice->id(), new \DateTimeImmutable('2026-10-17 09:30:00'));
        $this->service()->delete($alice->id(), self::PASSWORD);

        self::assertArrayNotHasKey($alice->id(), $this->users->lastLoginByUser(), 'la date de connexion est effacée avec le compte');
        $after = $this->users->findById($alice->id());
        self::assertNotNull($after, 'la ligne reste : les conversations et réservations des autres y sont rattachées');
        self::assertSame('Ancien membre', $after->displayName());
        self::assertSame('ancien-membre-' . $alice->id() . '@supprime.invalid', $after->email());
        self::assertFalse($after->isActive());
        self::assertNull($this->users->findByEmail('alice@rehearsalbox.test'), 'l\'adresse est libérée');
        self::assertFalse((new FastPasswordHasher())->verify(self::PASSWORD, $after->passwordHash()), 'plus de mot de passe valable');
        self::assertGreaterThan($alice->sessionVersion(), $after->sessionVersion(), 'toutes les sessions sont fermées');
        self::assertSame([], $this->groups->findByMember($alice->id()), 'plus membre d\'aucun groupe');
    }

    #[Test]
    public function testTheMessagesStayInTheConversationsOfOthersUnderTheFormerMemberName(): void
    {
        $alice = $this->person('Alice');
        $bob = $this->person('Bob');
        $alpha = $this->group('Alpha', $alice)->id();
        $beta = $this->group('Beta', $bob)->id();
        $conversation = (new MysqlConversationRepository($this->pdo, TestMessageCipher::make()))->create($alpha, $beta, null, $this->now, $alice->id());
        $messages = new MysqlConversationMessageRepository($this->pdo, TestMessageCipher::make());
        $messages->addMessage($conversation->id(), $alice->id(), 'Salut Bob', $this->now);

        $this->service()->delete($alice->id(), self::PASSWORD);

        $remaining = $messages->messagesOf($conversation->id());
        self::assertCount(1, $remaining);
        self::assertSame('Ancien membre', $remaining[0]->authorName());
        self::assertSame('Salut Bob', $remaining[0]->body());
    }

    #[Test]
    public function testPendingResetAndEmailChangeLinksStopWorking(): void
    {
        $alice = $this->person('Alice');
        $resets = new MysqlPasswordResetRepository($this->pdo);
        $changes = new MysqlEmailChangeRepository($this->pdo);
        $now = new \DateTimeImmutable('2026-10-20 10:00:00');
        $resets->create($alice->id(), ResetToken::hash('lien-reinitialisation'), $now->modify('+1 hour'), $now);
        $changes->create($alice->id(), 'nouvelle@rehearsalbox.test', ResetToken::hash('lien-changement'), $now->modify('+1 hour'), $now);

        $this->service()->delete($alice->id(), self::PASSWORD);

        self::assertNull($resets->consume(ResetToken::hash('lien-reinitialisation'), $now));
        self::assertNull($changes->consume(ResetToken::hash('lien-changement'), $now));
    }

    #[Test]
    public function testAWrongPasswordChangesNothingAndCountsAsAFailedAttempt(): void
    {
        $alice = $this->person('Alice');

        try {
            $this->service()->delete($alice->id(), 'faux');
            self::fail('le mot de passe est redemandé');
        } catch (UserValidationException $e) {
            self::assertArrayHasKey('currentPassword', $e->fields());
        }

        $after = $this->users->findById($alice->id());
        self::assertSame('Alice', $after->displayName());
        self::assertTrue($after->isActive());
        self::assertSame(1, $after->failedLoginAttempts());
        self::assertSame([], $this->mailer->sent);
    }

    #[Test]
    public function testTheSoleManagerOfAGroupMustHandOverFirst(): void
    {
        $alice = $this->person('Alice');
        $bob = $this->person('Bob');
        $alpha = $this->group('Alpha', $alice, $bob)->id();
        $this->groups->removeMember($alpha, $alice->id());
        $this->groups->addMember($alpha, $alice->id(), GroupUserRole::Gestionnaire);

        try {
            $this->service()->delete($alice->id(), self::PASSWORD);
            self::fail('dernier gestionnaire');
        } catch (LastGroupManagerException) {
        }

        self::assertTrue($this->users->findById($alice->id())->isActive(), 'rien n\'a été fait');
        self::assertCount(1, $this->groups->findByMember($alice->id()));
    }

    #[Test]
    public function testAnAdministratorMustBeDemotedByAnotherFirst(): void
    {
        $admin = $this->person('Chef', UserRole::Admin);

        $this->expectException(UserAdminRuleException::class);
        $this->service()->delete($admin->id(), self::PASSWORD);
    }

    #[Test]
    public function testTheFormerAddressIsToldAfterwardsAndAMailFailureChangesNothing(): void
    {
        $alice = $this->person('Alice');
        $this->service()->delete($alice->id(), self::PASSWORD);

        self::assertCount(1, $this->mailer->sent);
        self::assertSame('alice@rehearsalbox.test', $this->mailer->sent[0]->getTo()[0]->getAddress());

        $bob = $this->person('Bob');
        $this->service(new ThrowingMailer())->delete($bob->id(), self::PASSWORD);
        self::assertFalse($this->users->findById($bob->id())->isActive(), 'l\'échec d\'envoi n\'annule pas la suppression');
    }

    #[Test]
    public function testAnUnknownAccountIsRefused(): void
    {
        $this->expectException(\App\Account\Exception\UserNotFoundException::class);
        $this->service()->delete(999999, self::PASSWORD);
    }
}
