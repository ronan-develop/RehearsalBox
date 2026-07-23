<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Service\GroupService;
use App\Tests\RepositoryTestCase;

final class GroupServiceTest extends RepositoryTestCase
{
    private function makeService(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);
        $service = new GroupService($groupRepository, $userRepository);

        return [$service, $groupRepository, $userRepository];
    }

    private function createUser(MysqlUserRepository $userRepository, string $email): User
    {
        return $userRepository->save(new User(
            id: 0,
            email: $email,
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: $email,
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
    }

    public function testCreateAddsGroup(): void
    {
        [$service] = $this->makeService();

        $group = $service->create('Black Sabbath Tribute', 'metal', '#e63946');

        self::assertSame('Black Sabbath Tribute', $group->name());
    }

    public function testAddMemberByEmailAddsExistingUser(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $service->create('Groupe Test', null, null);
        $user = $this->createUser($userRepository, 'alice@rehearsalbox.test');

        $service->addMemberByEmail($group->id(), 'alice@rehearsalbox.test');

        self::assertTrue($groupRepository->isMember($group->id(), $user->id()));
    }

    public function testAddMemberByEmailWithUnknownEmailThrowsInvalidArgument(): void
    {
        [$service] = $this->makeService();
        $group = $service->create('Groupe Test', null, null);

        $this->expectException(\InvalidArgumentException::class);

        $service->addMemberByEmail($group->id(), 'inconnu@rehearsalbox.test');
    }

    public function testRemoveMemberRemovesExistingMember(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $service->create('Groupe Test', null, null);
        $user = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $service->addMemberByEmail($group->id(), 'bob@rehearsalbox.test');

        $service->removeMember($group->id(), $user->id());

        self::assertFalse($groupRepository->isMember($group->id(), $user->id()));
    }

    public function testFindAllReturnsEveryGroup(): void
    {
        [$service] = $this->makeService();
        $service->create('Groupe A', null, null);
        $service->create('Groupe B', null, null);

        self::assertCount(2, $service->findAll());
    }
}
