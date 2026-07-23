<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Tests\RepositoryTestCase;

final class MysqlGroupRepositoryTest extends RepositoryTestCase
{
    public function testSaveThenFindByIdReturnsSameGroup(): void
    {
        $repository = new MysqlGroupRepository($this->pdo);

        $inserted = $repository->save(new Group(0, 'Black Sabbath Tribute', 'metal', '#e63946'));

        $found = $repository->findById($inserted->id());

        self::assertNotNull($found);
        self::assertSame('Black Sabbath Tribute', $found->name());
        self::assertSame('metal', $found->genre());
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $repository = new MysqlGroupRepository($this->pdo);

        self::assertNull($repository->findById(9999));
    }

    public function testFindAllReturnsEveryGroup(): void
    {
        $repository = new MysqlGroupRepository($this->pdo);
        $repository->save(new Group(0, 'Groupe A', null, null));
        $repository->save(new Group(0, 'Groupe B', null, null));

        $all = $repository->findAll();

        self::assertCount(2, $all);
    }

    public function testAddMemberThenIsMemberReturnsTrue(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null));
        $user = $userRepository->save($this->newUser('alice@rehearsalbox.test'));

        $groupRepository->addMember($group->id(), $user->id());

        self::assertTrue($groupRepository->isMember($group->id(), $user->id()));
    }

    public function testIsMemberReturnsFalseWhenNotAMember(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null));
        $user = $userRepository->save($this->newUser('bob@rehearsalbox.test'));

        self::assertFalse($groupRepository->isMember($group->id(), $user->id()));
    }

    public function testFindByMemberReturnsOnlyGroupsTheUserBelongsTo(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $groupA = $groupRepository->save(new Group(0, 'Groupe A', null, null));
        $groupB = $groupRepository->save(new Group(0, 'Groupe B', null, null));
        $user = $userRepository->save($this->newUser('dana@rehearsalbox.test'));
        $groupRepository->addMember($groupA->id(), $user->id());

        $found = $groupRepository->findByMember($user->id());

        self::assertCount(1, $found);
        self::assertSame($groupA->id(), $found[0]->id());
    }

    public function testRemoveMemberThenIsMemberReturnsFalse(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null));
        $user = $userRepository->save($this->newUser('chris@rehearsalbox.test'));
        $groupRepository->addMember($group->id(), $user->id());

        $groupRepository->removeMember($group->id(), $user->id());

        self::assertFalse($groupRepository->isMember($group->id(), $user->id()));
    }

    private function newUser(string $email): User
    {
        return new User(
            id: 0,
            email: $email,
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: 'Test',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        );
    }
}
