<?php

declare(strict_types=1);

namespace App\Tests\Group\Service;

use App\Group\Entity\GroupUserRole;
use App\Account\Entity\UserRole;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Group\Repository\MysqlGroupManagerRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Security\Exception\AccessDeniedException;
use App\Group\Service\GroupManagerService;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class GroupManagerServiceTest extends RepositoryTestCase
{
    private function makeService(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $service = new GroupManagerService($groupRepository, new MysqlGroupManagerRepository($this->pdo));

        return [$service, $groupRepository, new MysqlUserRepository($this->pdo)];
    }

    private function createUser(MysqlUserRepository $userRepository, string $email): User
    {
        return $userRepository->save(new User(id: 0, email: $email, passwordHash: password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]), displayName: $email, role: UserRole::Musicien, isActive: true, failedLoginAttempts: 0, lockedUntil: null));
    }

    #[Test]

    public function testPromoteMemberByGestionnaireChangesRole(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $member = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $member->id());

        $service->promoteMember($group->id(), $member->id(), $manager->id());

        self::assertSame(GroupUserRole::Gestionnaire, $groupRepository->roleOf($group->id(), $member->id()));
    }
    #[Test]

    public function testPromoteMemberByNonGestionnaireThrowsAccessDenied(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $actor = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $actor->id());
        $member = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $member->id());

        $this->expectException(AccessDeniedException::class);

        $service->promoteMember($group->id(), $member->id(), $actor->id());
    }
    #[Test]

    public function testDemoteMemberByGestionnaireChangesRole(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $otherManager = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $otherManager->id(), GroupUserRole::Gestionnaire);

        $service->demoteMember($group->id(), $otherManager->id(), $manager->id());

        self::assertSame(GroupUserRole::Membre, $groupRepository->roleOf($group->id(), $otherManager->id()));
    }
    #[Test]

    public function testDemoteLastManagerThrowsLogicException(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);

        $this->expectException(\LogicException::class);

        $service->demoteMember($group->id(), $manager->id(), $manager->id());
    }
    #[Test]

    public function testDemoteMemberByNonGestionnaireThrowsAccessDenied(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $actor = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $actor->id());

        $this->expectException(AccessDeniedException::class);

        $service->demoteMember($group->id(), $manager->id(), $actor->id());
    }

    #[Test]
    public function testAssertNotLastManagerRefusesTheOnlyManagerWithADedicatedException(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $member = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $groupRepository->addMember($group->id(), $member->id());

        $this->pdo->beginTransaction();
        try {
            try {
                $service->assertNotLastManager($group->id(), $manager->id());
                self::fail('Refus attendu');
            } catch (\App\Group\Exception\LastGroupManagerException $e) {
                self::assertSame('Impossible de retirer ou rétrograder le dernier gestionnaire du groupe.', $e->getMessage());
            }
            $service->assertNotLastManager($group->id(), $member->id()); // un simple membre n'est jamais concerné
            $this->addToAssertionCount(1);
        } finally {
            $this->pdo->rollBack();
        }
    }

    #[Test]
    public function testWithTwoManagersEitherMayLeave(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $first = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $second = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $first->id(), GroupUserRole::Gestionnaire);
        $groupRepository->addMember($group->id(), $second->id(), GroupUserRole::Gestionnaire);

        $this->pdo->beginTransaction();
        try {
            $service->assertNotLastManager($group->id(), $first->id());
            $service->assertNotLastManager($group->id(), $second->id());
            $this->addToAssertionCount(2);
        } finally {
            $this->pdo->rollBack();
        }
    }

    #[Test]
    public function testTwoAdminsLockingTheManagersOfTheSameGroupWaitForEachOtherButNotOtherGroups(): void
    {
        [, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe A', null, null, 'a@example.test'));
        $other = $groupRepository->save(new Group(0, 'Groupe B', null, null, 'b@example.test'));
        $manager = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $second = \App\Tests\Database\TestDatabase::connection();
        $second->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $this->pdo->beginTransaction();
        try {
            (new MysqlGroupManagerRepository($this->pdo))->lockManagerIds($group->id());

            $second->beginTransaction();
            try {
                (new MysqlGroupManagerRepository($second))->lockManagerIds($group->id());
                self::fail('le second administrateur doit attendre le premier');
            } catch (\PDOException $e) {
                self::assertSame(1205, $e->errorInfo[1] ?? null, 'délai d\'attente du verrou : il était bien bloqué');
            }
            $second->rollBack();

            $second->beginTransaction();
            (new MysqlGroupManagerRepository($second))->lockManagerIds($other->id()); // un autre groupe n'est jamais gêné
            $second->rollBack();
        } finally {
            $this->pdo->rollBack();
        }
    }
}
