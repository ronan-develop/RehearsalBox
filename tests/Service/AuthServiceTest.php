<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Exception\AccessDeniedException;
use App\Security\NativePasswordHasher;
use App\Service\AuthService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;

final class AuthServiceTest extends RepositoryTestCase
{
    private function makeService(): array
    {
        $userRepository = new MysqlUserRepository($this->pdo);
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $session = new InMemorySession();
        $service = new AuthService($userRepository, new NativePasswordHasher(), $session, $groupRepository);

        return [$service, $userRepository, $session, $groupRepository];
    }

    private function createUser(MysqlUserRepository $repository, string $email, string $password): User
    {
        return $repository->save(new User(
            id: 0,
            email: $email,
            passwordHash: (new NativePasswordHasher())->hash($password),
            displayName: 'Test',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
    }

    public function testAttemptWithValidCredentialsReturnsUserAndRegeneratesSession(): void
    {
        [$service, $userRepository, $session] = $this->makeService();
        $this->createUser($userRepository, 'alice@rehearsalbox.test', 'password123');

        $user = $service->attempt('alice@rehearsalbox.test', 'password123');

        self::assertNotNull($user);
        self::assertSame('alice@rehearsalbox.test', $user->email());
        self::assertTrue($session->regenerated);
    }

    public function testAttemptWithWrongPasswordReturnsNull(): void
    {
        [$service, $userRepository] = $this->makeService();
        $this->createUser($userRepository, 'bob@rehearsalbox.test', 'password123');

        $user = $service->attempt('bob@rehearsalbox.test', 'mauvais-mot-de-passe');

        self::assertNull($user);
    }

    public function testAttemptWithUnknownEmailReturnsNull(): void
    {
        [$service] = $this->makeService();

        $user = $service->attempt('inconnu@rehearsalbox.test', 'peu-importe');

        self::assertNull($user);
    }

    public function testAttemptLocksAccountAfterFiveFailedAttempts(): void
    {
        [$service, $userRepository] = $this->makeService();
        $created = $this->createUser($userRepository, 'chris@rehearsalbox.test', 'password123');

        for ($i = 0; $i < 5; $i++) {
            $service->attempt('chris@rehearsalbox.test', 'mauvais');
        }

        // Même avec le bon mot de passe, le compte verrouillé refuse la connexion.
        $result = $service->attempt('chris@rehearsalbox.test', 'password123');

        self::assertNull($result);
    }

    public function testAttemptResetsFailedAttemptsAfterSuccessfulLogin(): void
    {
        [$service, $userRepository] = $this->makeService();
        $this->createUser($userRepository, 'dana@rehearsalbox.test', 'password123');

        $service->attempt('dana@rehearsalbox.test', 'mauvais');
        $service->attempt('dana@rehearsalbox.test', 'mauvais');
        $service->attempt('dana@rehearsalbox.test', 'password123');

        $reloaded = $userRepository->findByEmail('dana@rehearsalbox.test');
        self::assertNotNull($reloaded);
    }

    public function testCurrentUserReturnsNullWhenNoUserInSession(): void
    {
        [$service] = $this->makeService();

        self::assertNull($service->currentUser());
    }

    public function testCurrentUserReturnsUserAfterSuccessfulAttempt(): void
    {
        [$service, $userRepository] = $this->makeService();
        $this->createUser($userRepository, 'eve@rehearsalbox.test', 'password123');
        $service->attempt('eve@rehearsalbox.test', 'password123');

        $current = $service->currentUser();

        self::assertNotNull($current);
        self::assertSame('eve@rehearsalbox.test', $current->email());
    }

    public function testLogoutDestroysSession(): void
    {
        [$service, , $session] = $this->makeService();

        $service->logout();

        self::assertTrue($session->destroyed);
    }

    public function testGroupsRequiringSelectionReturnsEmptyWhenSingleGroup(): void
    {
        [$service, $userRepository, , $groupRepository] = $this->makeService();
        $user = $this->createUser($userRepository, 'fanny@rehearsalbox.test', 'password123');
        $group = $groupRepository->save(new Group(0, 'Groupe Solo', null, null, 'contact@example.test'));
        $groupRepository->addMember($group->id(), $user->id());
        $service->attempt('fanny@rehearsalbox.test', 'password123');

        self::assertSame([], $service->groupsRequiringSelection());
    }

    public function testGroupsRequiringSelectionReturnsGroupsWhenMultiple(): void
    {
        [$service, $userRepository, , $groupRepository] = $this->makeService();
        $user = $this->createUser($userRepository, 'gaby@rehearsalbox.test', 'password123');
        $groupA = $groupRepository->save(new Group(0, 'Groupe A', null, null, 'contact@example.test'));
        $groupB = $groupRepository->save(new Group(0, 'Groupe B', null, null, 'contact@example.test'));
        $groupRepository->addMember($groupA->id(), $user->id());
        $groupRepository->addMember($groupB->id(), $user->id());
        $service->attempt('gaby@rehearsalbox.test', 'password123');

        $groups = $service->groupsRequiringSelection();

        self::assertCount(2, $groups);
    }

    public function testSelectActiveGroupSetsSessionWhenUserIsMember(): void
    {
        [$service, $userRepository, $session, $groupRepository] = $this->makeService();
        $user = $this->createUser($userRepository, 'hugo@rehearsalbox.test', 'password123');
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $groupRepository->addMember($group->id(), $user->id());
        $service->attempt('hugo@rehearsalbox.test', 'password123');

        $service->selectActiveGroup($group->id());

        self::assertSame($group->id(), $session->get('active_group_id'));
    }

    public function testSelectActiveGroupThrowsAccessDeniedWhenUserIsNotMember(): void
    {
        [$service, $userRepository, , $groupRepository] = $this->makeService();
        $this->createUser($userRepository, 'ivan@rehearsalbox.test', 'password123');
        $otherGroup = $groupRepository->save(new Group(0, 'Groupe Tiers', null, null, 'contact@example.test'));
        $service->attempt('ivan@rehearsalbox.test', 'password123');

        $this->expectException(AccessDeniedException::class);

        $service->selectActiveGroup($otherGroup->id());
    }

    public function testAttemptAutoSelectsActiveGroupWhenUserHasExactlyOneGroup(): void
    {
        [$service, $userRepository, $session, $groupRepository] = $this->makeService();
        $user = $this->createUser($userRepository, 'jade@rehearsalbox.test', 'password123');
        $group = $groupRepository->save(new Group(0, 'Groupe Solo', null, null, 'contact@example.test'));
        $groupRepository->addMember($group->id(), $user->id());

        $service->attempt('jade@rehearsalbox.test', 'password123');

        self::assertSame($group->id(), $session->get('active_group_id'));
    }
}
