<?php

declare(strict_types=1);

namespace App\Tests\Group\Repository;

use App\Group\Entity\GroupUserRole;
use App\Account\Entity\UserRole;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Group\Repository\MysqlGroupManagerRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Tests\Database\RepositoryTestCase;
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

    public function testLockManagerIdsListsOnlyGestionnaireRoleInOrder(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $userRepository->save($this->newUser('alice@rehearsalbox.test'));
        $member = $userRepository->save($this->newUser('bob@rehearsalbox.test'));
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $groupRepository->addMember($group->id(), $member->id());

        $this->pdo->beginTransaction();
        try {
            self::assertSame([$manager->id()], (new MysqlGroupManagerRepository($this->pdo))->lockManagerIds($group->id()));
        } finally {
            $this->pdo->rollBack();
        }
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
