<?php

declare(strict_types=1);

namespace App\Tests\Group\Service;

use App\Account\Exception\UserNotFoundException;
use App\Account\Exception\UserValidationException;
use App\Database\TransactionRunner;
use App\Group\Entity\GroupUserRole;
use App\Group\Exception\LastGroupManagerException;
use App\Group\Repository\MysqlGroupManagerRepository;
use App\Group\Service\GroupManagerService;
use App\Group\Service\GroupMembershipAdminService;
use App\Messaging\Repository\ConversationRepositoryInterface as Box;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\RecordingLogger;
use App\Tests\Scenarios\ConversationWorld;
use PHPUnit\Framework\Attributes\Test;

/** #272 : l'administrateur ajoute, retire, déplace et change le rôle d'un compte dans les groupes, sans jamais laisser un groupe sans gestionnaire. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class GroupMembershipAdminServiceTest extends RepositoryTestCase
{
    use ConversationWorld;

    private const ADMIN = 1;

    private GroupMembershipAdminService $memberships;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
        $this->logger = new RecordingLogger();
        $managers = new MysqlGroupManagerRepository($this->pdo);
        $this->memberships = new GroupMembershipAdminService(
            $this->users,
            $this->groups,
            $managers,
            new GroupManagerService($this->groups, $managers),
            new TransactionRunner($this->pdo),
            $this->logger,
        );
    }

    // --- Ajout et rôle ---------------------------------------------------------------------------

    #[Test]
    public function testItAddsAnAccountToSeveralGroupsAndIsIdempotent(): void
    {
        [$alice, , $alpha, $beta] = $this->world();
        $carol = $this->user('Carol');

        $this->memberships->setMembership($carol->id(), $alpha->id(), GroupUserRole::Membre, self::ADMIN);
        $this->memberships->setMembership($carol->id(), $beta->id(), GroupUserRole::Gestionnaire, self::ADMIN);
        $this->memberships->setMembership($carol->id(), $alpha->id(), GroupUserRole::Membre, self::ADMIN);

        self::assertSame(GroupUserRole::Membre, $this->groups->roleOf($alpha->id(), $carol->id()));
        self::assertSame(GroupUserRole::Gestionnaire, $this->groups->roleOf($beta->id(), $carol->id()));
        self::assertSame(GroupUserRole::Membre, $this->groups->roleOf($alpha->id(), $alice->id()), 'les autres membres ne bougent pas');
    }

    #[Test]
    public function testItChangesTheRoleAndRefusesToDemoteTheLastManager(): void
    {
        [$alice, , $alpha] = $this->world();
        $carol = $this->user('Carol');
        $this->groups->addMember($alpha->id(), $carol->id(), GroupUserRole::Gestionnaire);

        $this->memberships->setMembership($alice->id(), $alpha->id(), GroupUserRole::Gestionnaire, self::ADMIN);
        self::assertSame(GroupUserRole::Gestionnaire, $this->groups->roleOf($alpha->id(), $alice->id()));
        $this->memberships->setMembership($carol->id(), $alpha->id(), GroupUserRole::Membre, self::ADMIN); // deux gestionnaires : accepté
        self::assertSame(GroupUserRole::Membre, $this->groups->roleOf($alpha->id(), $carol->id()));

        $this->expectException(LastGroupManagerException::class); // Alice est maintenant la seule gestionnaire
        $this->memberships->setMembership($alice->id(), $alpha->id(), GroupUserRole::Membre, self::ADMIN);
    }

    #[Test]
    public function testAnUnknownAccountOrGroupIsRefusedAndNothingIsWritten(): void
    {
        [, , $alpha] = $this->world();
        $carol = $this->user('Carol');

        try {
            $this->memberships->setMembership(999999, $alpha->id(), GroupUserRole::Membre, self::ADMIN);
            self::fail('Refus attendu');
        } catch (UserNotFoundException) {
        }
        try {
            $this->memberships->setMembership($carol->id(), 999999, GroupUserRole::Membre, self::ADMIN);
            self::fail('Refus attendu');
        } catch (UserValidationException $e) {
            self::assertSame(['groupId' => 'Groupe introuvable.'], $e->fields());
        }
        self::assertNull($this->groups->roleOf($alpha->id(), $carol->id()));
    }

    // --- Retrait ---------------------------------------------------------------------------------

    #[Test]
    public function testRemovingLeavesAnAccountWithoutAnyGroupWhichIsAllowed(): void
    {
        [$alice, , $alpha] = $this->world();

        $this->memberships->removeMembership($alice->id(), $alpha->id(), self::ADMIN);

        self::assertFalse($this->groups->isMember($alpha->id(), $alice->id()));
        self::assertSame([], $this->groups->findByMember($alice->id()));
    }

    #[Test]
    public function testRemovingSomeoneWhoIsNotAMemberChangesNothing(): void
    {
        [, , $alpha] = $this->world();
        $carol = $this->user('Carol');

        $this->memberships->removeMembership($carol->id(), $alpha->id(), self::ADMIN);

        self::assertSame('', $this->logger->text(), 'rien n\'a changé : rien n\'est journalisé');
    }

    #[Test]
    public function testTheLastManagerCannotBeRemovedButOneOfTwoCan(): void
    {
        [$alice, , $alpha] = $this->world();
        $carol = $this->user('Carol');
        (new MysqlGroupManagerRepository($this->pdo))->promoteToManager($alpha->id(), $alice->id()); // addMember conserve le rôle d'un membre existant

        try {
            $this->memberships->removeMembership($alice->id(), $alpha->id(), self::ADMIN);
            self::fail('Refus attendu');
        } catch (LastGroupManagerException) {
        }
        self::assertTrue($this->groups->isMember($alpha->id(), $alice->id()));

        $this->groups->addMember($alpha->id(), $carol->id(), GroupUserRole::Gestionnaire);
        $this->memberships->removeMembership($alice->id(), $alpha->id(), self::ADMIN);
        self::assertFalse($this->groups->isMember($alpha->id(), $alice->id()));
    }

    #[Test]
    public function testAfterTheRemovalTheGroupConversationDisappearsForThePersonButNotForTheOthersAndNothingIsDeleted(): void
    {
        [$alice, $bob, $alpha, $beta] = $this->world();
        $conversation = $this->service->start($alice->id(), $alpha->id(), $beta->id(), 'Bonjour', 'Concert');
        $this->service->reply($bob->id(), $conversation->id(), 'Salut');
        $messagesBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM conversation_messages')->fetchColumn();
        self::assertCount(1, $this->reader->listFor($alice->id(), Box::BOX_ACTIVE), 'visible avant');

        $this->memberships->removeMembership($alice->id(), $alpha->id(), self::ADMIN);

        self::assertSame([], $this->reader->listFor($alice->id(), Box::BOX_ACTIVE), 'invisible après');
        self::assertCount(1, $this->reader->listFor($bob->id(), Box::BOX_ACTIVE), 'l\'autre groupe la voit toujours');
        self::assertSame($messagesBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM conversation_messages')->fetchColumn(), 'ses messages restent');
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn());
    }

    #[Test]
    public function testAGuestOfTheConversationKeepsAccessAfterLeavingTheGroup(): void
    {
        [$alice, , $alpha, $beta] = $this->world();
        $conversation = $this->service->start($alice->id(), $alpha->id(), $beta->id(), 'Bonjour', 'Concert');
        (new \App\Messaging\Repository\Participation\MysqlConversationGuestRepository($this->pdo))->add($conversation->id(), $alice->id(), $alice->id(), new \DateTimeImmutable('2026-10-12 10:00:00'));

        $this->memberships->removeMembership($alice->id(), $alpha->id(), self::ADMIN);

        self::assertCount(1, $this->reader->listFor($alice->id(), Box::BOX_ACTIVE), 'invitée : l\'accès ne dépend pas du groupe');
    }

    // --- Déplacement -----------------------------------------------------------------------------

    #[Test]
    public function testMovingRemovesFromTheFirstGroupAndAddsAsAMemberToTheSecond(): void
    {
        [$alice, , $alpha, $beta] = $this->world();

        $this->memberships->moveMembership($alice->id(), $alpha->id(), $beta->id(), self::ADMIN);

        self::assertFalse($this->groups->isMember($alpha->id(), $alice->id()));
        self::assertSame(GroupUserRole::Membre, $this->groups->roleOf($beta->id(), $alice->id()));
    }

    #[Test]
    public function testMovingTheLastManagerOfTheSourceGroupIsRefusedAndNothingMoves(): void
    {
        [$alice, , $alpha, $beta] = $this->world();
        (new MysqlGroupManagerRepository($this->pdo))->promoteToManager($alpha->id(), $alice->id()); // addMember conserve le rôle d'un membre existant

        try {
            $this->memberships->moveMembership($alice->id(), $alpha->id(), $beta->id(), self::ADMIN);
            self::fail('Refus attendu');
        } catch (LastGroupManagerException) {
        }

        self::assertTrue($this->groups->isMember($alpha->id(), $alice->id()));
        self::assertFalse($this->groups->isMember($beta->id(), $alice->id()));
    }

    #[Test]
    public function testAnInvalidMoveIsRefusedWithoutAnyChange(): void
    {
        [$alice, , $alpha, $beta] = $this->world();
        $carol = $this->user('Carol');

        foreach ([[$alice->id(), $alpha->id(), $alpha->id()], [$alice->id(), $alpha->id(), 999999], [$carol->id(), $alpha->id(), $beta->id()], [$alice->id(), 999999, $beta->id()]] as [$user, $from, $to]) {
            try {
                $this->memberships->moveMembership($user, $from, $to, self::ADMIN);
                self::fail('Refus attendu');
            } catch (UserValidationException $e) {
                self::assertArrayHasKey('groupId', $e->fields());
            }
        }
        self::assertTrue($this->groups->isMember($alpha->id(), $alice->id()));
        self::assertFalse($this->groups->isMember($beta->id(), $alice->id()));
    }

    // --- Journal ---------------------------------------------------------------------------------

    #[Test]
    public function testEachChangeIsJournaledWithIdentifiersOnly(): void
    {
        [$alice, , $alpha, $beta] = $this->world();

        $this->memberships->moveMembership($alice->id(), $alpha->id(), $beta->id(), self::ADMIN);

        $text = $this->logger->text();
        self::assertStringContainsString('Administration : appartenance déplacée', $text);
        self::assertStringContainsString('"actor":' . self::ADMIN, $text);
        self::assertStringContainsString('"user":' . $alice->id(), $text);
        self::assertStringNotContainsString('@', $text);
        self::assertStringNotContainsString('Alpha', $text);
    }
}
