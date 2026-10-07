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
use App\Tests\RepositoryTestCase;
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
}
