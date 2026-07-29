<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Enum\GroupUserRole;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\LineupMember;
use App\Entity\UpcomingShow;
use App\Entity\User;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Tests\RepositoryTestCase;

final class MysqlGroupRepositoryTest extends RepositoryTestCase
{
    public function testSaveThenFindByIdReturnsSameGroup(): void
    {
        $repository = new MysqlGroupRepository($this->pdo);

        $inserted = $repository->save(new Group(0, 'Black Sabbath Tribute', 'metal', '#e63946', 'contact@example.test'));

        $found = $repository->findById($inserted->id());

        self::assertNotNull($found);
        self::assertSame('Black Sabbath Tribute', $found->name());
        self::assertSame('metal', $found->genre());
        self::assertSame('contact@example.test', $found->contactEmail());
    }

    public function testFindBySlugReturnsMatchingGroup(): void
    {
        $repository = new MysqlGroupRepository($this->pdo);
        $repository->save(new Group(0, 'Black Sabbath Tribute', null, null, 'contact@example.test'));

        $found = $repository->findBySlug('black-sabbath-tribute');

        self::assertNotNull($found);
        self::assertSame('Black Sabbath Tribute', $found->name());
    }

    public function testFindBySlugReturnsNullWhenNoMatch(): void
    {
        $repository = new MysqlGroupRepository($this->pdo);

        self::assertNull($repository->findBySlug('groupe-inexistant'));
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $repository = new MysqlGroupRepository($this->pdo);

        self::assertNull($repository->findById(9999));
    }

    public function testFindAllReturnsEveryGroup(): void
    {
        $repository = new MysqlGroupRepository($this->pdo);
        $repository->save(new Group(0, 'Groupe A', null, null, 'contact@example.test'));
        $repository->save(new Group(0, 'Groupe B', null, null, 'contact@example.test'));

        $all = $repository->findAll();

        self::assertCount(2, $all);
    }

    public function testAddMemberThenIsMemberReturnsTrue(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $user = $userRepository->save($this->newUser('alice@rehearsalbox.test'));

        $groupRepository->addMember($group->id(), $user->id());

        self::assertTrue($groupRepository->isMember($group->id(), $user->id()));
    }

    public function testIsMemberReturnsFalseWhenNotAMember(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $user = $userRepository->save($this->newUser('bob@rehearsalbox.test'));

        self::assertFalse($groupRepository->isMember($group->id(), $user->id()));
    }

    public function testFindByMemberReturnsOnlyGroupsTheUserBelongsTo(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $groupA = $groupRepository->save(new Group(0, 'Groupe A', null, null, 'contact@example.test'));
        $groupB = $groupRepository->save(new Group(0, 'Groupe B', null, null, 'contact@example.test'));
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

        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $user = $userRepository->save($this->newUser('chris@rehearsalbox.test'));
        $groupRepository->addMember($group->id(), $user->id());

        $groupRepository->removeMember($group->id(), $user->id());

        self::assertFalse($groupRepository->isMember($group->id(), $user->id()));
    }

    public function testDeleteThenFindByIdReturnsNull(): void
    {
        $repository = new MysqlGroupRepository($this->pdo);
        $group = $repository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));

        $repository->delete($group->id());

        self::assertNull($repository->findById($group->id()));
    }

    public function testDeleteCascadesToGroupMembers(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $user = $userRepository->save($this->newUser('alice@rehearsalbox.test'));
        $groupRepository->addMember($group->id(), $user->id());

        $groupRepository->delete($group->id());

        self::assertFalse($groupRepository->isMember($group->id(), $user->id()));
    }

    public function testAddMemberDefaultsToMembreRole(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $user = $userRepository->save($this->newUser('alice@rehearsalbox.test'));

        $groupRepository->addMember($group->id(), $user->id());

        self::assertSame(GroupUserRole::Membre, $groupRepository->roleOf($group->id(), $user->id()));
    }

    public function testAddMemberWithExplicitGestionnaireRole(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $user = $userRepository->save($this->newUser('alice@rehearsalbox.test'));

        $groupRepository->addMember($group->id(), $user->id(), GroupUserRole::Gestionnaire);

        self::assertSame(GroupUserRole::Gestionnaire, $groupRepository->roleOf($group->id(), $user->id()));
    }

    public function testRoleOfReturnsNullWhenNotAMember(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $user = $userRepository->save($this->newUser('alice@rehearsalbox.test'));

        self::assertNull($groupRepository->roleOf($group->id(), $user->id()));
    }

    public function testPromoteToManagerChangesRole(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $user = $userRepository->save($this->newUser('alice@rehearsalbox.test'));
        $groupRepository->addMember($group->id(), $user->id());

        $groupRepository->promoteToManager($group->id(), $user->id());

        self::assertSame(GroupUserRole::Gestionnaire, $groupRepository->roleOf($group->id(), $user->id()));
    }

    public function testDemoteToMemberChangesRole(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $user = $userRepository->save($this->newUser('alice@rehearsalbox.test'));
        $groupRepository->addMember($group->id(), $user->id(), GroupUserRole::Gestionnaire);

        $groupRepository->demoteToMember($group->id(), $user->id());

        self::assertSame(GroupUserRole::Membre, $groupRepository->roleOf($group->id(), $user->id()));
    }

    public function testSaveThenFindByIdReturnsLineupAndUpcomingShows(): void
    {
        $repository = new MysqlGroupRepository($this->pdo);
        $group = new Group(
            0,
            'Groupe Test',
            null,
            null,
            'contact@example.test',
            [new LineupMember('Alice', 'Guitare'), new LineupMember('Bob', 'Batterie')],
            [new UpcomingShow('2026-09-12', 'Le Point Éphémère')],
        );

        $inserted = $repository->save($group);
        $found = $repository->findById($inserted->id());

        self::assertCount(2, $found->lineup());
        self::assertSame('Alice', $found->lineup()[0]->name());
        self::assertSame('Guitare', $found->lineup()[0]->instrument());
        self::assertCount(1, $found->upcomingShows());
        self::assertSame('Le Point Éphémère', $found->upcomingShows()[0]->venue());
    }

    public function testSaveWithoutLineupReturnsEmptyArrays(): void
    {
        $repository = new MysqlGroupRepository($this->pdo);

        $inserted = $repository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $found = $repository->findById($inserted->id());

        self::assertSame([], $found->lineup());
        self::assertSame([], $found->upcomingShows());
    }

    public function testCountManagersCountsOnlyGestionnaireRole(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $userRepository->save($this->newUser('alice@rehearsalbox.test'));
        $member = $userRepository->save($this->newUser('bob@rehearsalbox.test'));
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $groupRepository->addMember($group->id(), $member->id());

        self::assertSame(1, $groupRepository->countManagers($group->id()));
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
