<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Enum\GroupUserRole;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\MysqlGroupManagerRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class MysqlGroupManagerRepositoryTest extends RepositoryTestCase
{
    #[Test]

    public function testPromoteToManagerChangesRole(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $user = $userRepository->save($this->newUser('alice@rehearsalbox.test'));
        $groupRepository->addMember($group->id(), $user->id());

        (new MysqlGroupManagerRepository($this->pdo))->promoteToManager($group->id(), $user->id());

        self::assertSame(GroupUserRole::Gestionnaire, $groupRepository->roleOf($group->id(), $user->id()));
    }
    #[Test]

    public function testDemoteToMemberChangesRole(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $user = $userRepository->save($this->newUser('alice@rehearsalbox.test'));
        $groupRepository->addMember($group->id(), $user->id(), GroupUserRole::Gestionnaire);

        (new MysqlGroupManagerRepository($this->pdo))->demoteToMember($group->id(), $user->id());

        self::assertSame(GroupUserRole::Membre, $groupRepository->roleOf($group->id(), $user->id()));
    }
    #[Test]

    public function testCountManagersCountsOnlyGestionnaireRole(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $userRepository->save($this->newUser('alice@rehearsalbox.test'));
        $member = $userRepository->save($this->newUser('bob@rehearsalbox.test'));
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $groupRepository->addMember($group->id(), $member->id());

        self::assertSame(1, (new MysqlGroupManagerRepository($this->pdo))->countManagers($group->id()));
    }

    private function newUser(string $email): User
    {
        return new User(
            id: 0,
            email: $email,
            passwordHash: password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]),
            displayName: 'Test',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        );
    }
}
