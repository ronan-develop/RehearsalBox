<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\GroupApiController;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Http\Request;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\Exception\AccessDeniedException;
use App\Security\NativePasswordHasher;
use App\Service\AuthService;
use App\Service\GroupService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;

final class GroupApiControllerTest extends RepositoryTestCase
{
    private function makeController(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $session = new InMemorySession();
        $authService = new AuthService($userRepository, new NativePasswordHasher(), $session);
        $authGuard = new AuthGuard($authService);
        $groupService = new GroupService($groupRepository, $userRepository);

        $controller = new GroupApiController($groupService, $authGuard);

        return [$controller, $groupRepository, $userRepository, $authService];
    }

    private function createUser(MysqlUserRepository $userRepository, string $email, UserRole $role): User
    {
        return $userRepository->save(new User(
            id: 0,
            email: $email,
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: $email,
            role: $role,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
    }

    public function testIndexReturnsAllGroups(): void
    {
        [$controller, , $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');

        $controller->store(new Request('POST', '/api/admin/groups', [], ['name' => 'Groupe Test', 'genre' => null, 'colorHex' => null], []));

        $response = $controller->index(new Request('GET', '/api/admin/groups', [], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertCount(1, json_decode($response->body(), true)['groups']);
    }

    public function testStoreByAdminReturns201(): void
    {
        [$controller, , $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');

        $request = new Request('POST', '/api/admin/groups', [], ['name' => 'Groupe Test', 'genre' => 'metal', 'colorHex' => '#e63946'], []);
        $response = $controller->store($request);

        self::assertSame(201, $response->statusCode());
    }

    public function testStoreByNonAdminThrowsAccessDenied(): void
    {
        [$controller, , $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'musicien@rehearsalbox.test', UserRole::Musicien);
        $authService->attempt('musicien@rehearsalbox.test', 'password');

        $this->expectException(AccessDeniedException::class);

        $controller->store(new Request('POST', '/api/admin/groups', [], ['name' => 'Groupe Test', 'genre' => null, 'colorHex' => null], []));
    }

    public function testAddMemberWithKnownEmailReturns200(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');
        $group = $groupRepository->save(new \App\Entity\Group(0, 'Groupe Test', null, null));
        $this->createUser($userRepository, 'alice@rehearsalbox.test', UserRole::Musicien);

        $request = new Request('POST', "/api/admin/groups/{$group->id()}/members", [], ['email' => 'alice@rehearsalbox.test'], []);
        $response = $controller->addMember($request, (string) $group->id());

        self::assertSame(200, $response->statusCode());
    }

    public function testAddMemberWithUnknownEmailReturns422(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');
        $group = $groupRepository->save(new \App\Entity\Group(0, 'Groupe Test', null, null));

        $request = new Request('POST', "/api/admin/groups/{$group->id()}/members", [], ['email' => 'inconnu@rehearsalbox.test'], []);
        $response = $controller->addMember($request, (string) $group->id());

        self::assertSame(422, $response->statusCode());
    }

    public function testRemoveMemberReturns204(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');
        $group = $groupRepository->save(new \App\Entity\Group(0, 'Groupe Test', null, null));
        $user = $this->createUser($userRepository, 'alice@rehearsalbox.test', UserRole::Musicien);
        $groupRepository->addMember($group->id(), $user->id());

        $response = $controller->removeMember(
            new Request('DELETE', "/api/admin/groups/{$group->id()}/members/{$user->id()}", [], [], []),
            (string) $group->id(),
            (string) $user->id(),
        );

        self::assertSame(204, $response->statusCode());
    }
}
