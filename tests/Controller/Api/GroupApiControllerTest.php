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
use PHPUnit\Framework\Attributes\Test;

final class GroupApiControllerTest extends RepositoryTestCase
{
    private function makeController(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $session = new InMemorySession();
        $authService = new AuthService($userRepository, new NativePasswordHasher(), $session, $groupRepository);
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

    #[Test]

    public function testIndexReturnsAllGroups(): void
    {
        [$controller, , $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');

        $controller->store(new Request('POST', '/api/admin/groups', [], ['name' => 'Groupe Test', 'genre' => null, 'colorHex' => null, 'contactEmail' => 'contact@example.test'], []));

        $response = $controller->index(new Request('GET', '/api/admin/groups', [], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertCount(1, json_decode($response->body(), true)['groups']);
    }

    #[Test]

    public function testStoreByAdminReturns201(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');

        $request = new Request('POST', '/api/admin/groups', [], ['name' => 'Groupe Test', 'genre' => 'metal', 'colorHex' => '#e63946', 'contactEmail' => 'contact@example.test'], []);
        $response = $controller->store($request);
        $body = json_decode($response->body(), true);

        self::assertSame(201, $response->statusCode());
        self::assertArrayNotHasKey('contactEmail', $body);
        $saved = $groupRepository->findById($body['id']);
        self::assertSame('contact@example.test', $saved->contactEmail());
    }

    #[Test]

    public function testStoreWithMissingContactEmailReturns422(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');

        $request = new Request('POST', '/api/admin/groups', [], ['name' => 'Groupe Test', 'genre' => null, 'colorHex' => null], []);
        $response = $controller->store($request);

        self::assertSame(422, $response->statusCode());
        self::assertCount(0, $groupRepository->findAll());
    }

    #[Test]

    public function testStoreWithInvalidContactEmailReturns422(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');

        $request = new Request('POST', '/api/admin/groups', [], ['name' => 'Groupe Test', 'genre' => null, 'colorHex' => null, 'contactEmail' => 'pas-un-email'], []);
        $response = $controller->store($request);

        self::assertSame(422, $response->statusCode());
        self::assertCount(0, $groupRepository->findAll());
    }

    #[Test]

    public function testStoreByNonAdminThrowsAccessDenied(): void
    {
        [$controller, , $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'musicien@rehearsalbox.test', UserRole::Musicien);
        $authService->attempt('musicien@rehearsalbox.test', 'password');

        $this->expectException(AccessDeniedException::class);

        $controller->store(new Request('POST', '/api/admin/groups', [], ['name' => 'Groupe Test', 'genre' => null, 'colorHex' => null, 'contactEmail' => 'contact@example.test'], []));
    }

    #[Test]

    public function testUpdateByAdminReturns200(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');
        $group = $groupRepository->save(new \App\Entity\Group(0, 'Groupe Test', null, null, 'contact@example.test'));

        $request = new Request('PATCH', "/api/admin/groups/{$group->id()}", [], ['name' => 'Nouveau Nom', 'genre' => 'punk', 'colorHex' => '#123456', 'contactEmail' => 'nouveau@example.test'], []);
        $response = $controller->update($request, (string) $group->id());
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->statusCode());
        self::assertSame('Nouveau Nom', $body['name']);
        $saved = $groupRepository->findById($group->id());
        self::assertSame('nouveau@example.test', $saved->contactEmail());
    }

    #[Test]

    public function testUpdateWithUnknownGroupReturns422(): void
    {
        [$controller, , $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');

        $request = new Request('PATCH', '/api/admin/groups/9999', [], ['name' => 'Nom', 'genre' => null, 'colorHex' => null, 'contactEmail' => 'contact@example.test'], []);
        $response = $controller->update($request, '9999');

        self::assertSame(422, $response->statusCode());
    }

    #[Test]

    public function testUpdateByNonAdminThrowsAccessDenied(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'musicien@rehearsalbox.test', UserRole::Musicien);
        $authService->attempt('musicien@rehearsalbox.test', 'password');
        $group = $groupRepository->save(new \App\Entity\Group(0, 'Groupe Test', null, null, 'contact@example.test'));

        $this->expectException(AccessDeniedException::class);

        $controller->update(
            new Request('PATCH', "/api/admin/groups/{$group->id()}", [], ['name' => 'Nom', 'genre' => null, 'colorHex' => null, 'contactEmail' => 'contact@example.test'], []),
            (string) $group->id(),
        );
    }

    #[Test]

    public function testDestroyByAdminReturns204(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');
        $group = $groupRepository->save(new \App\Entity\Group(0, 'Groupe Test', null, null, 'contact@example.test'));

        $response = $controller->destroy(new Request('DELETE', "/api/admin/groups/{$group->id()}", [], [], []), (string) $group->id());

        self::assertSame(204, $response->statusCode());
        self::assertNull($groupRepository->findById($group->id()));
    }

    #[Test]

    public function testDestroyByNonAdminThrowsAccessDenied(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'musicien@rehearsalbox.test', UserRole::Musicien);
        $authService->attempt('musicien@rehearsalbox.test', 'password');
        $group = $groupRepository->save(new \App\Entity\Group(0, 'Groupe Test', null, null, 'contact@example.test'));

        $this->expectException(AccessDeniedException::class);

        $controller->destroy(new Request('DELETE', "/api/admin/groups/{$group->id()}", [], [], []), (string) $group->id());
    }

    #[Test]

    public function testAddMemberWithKnownEmailReturns200(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');
        $group = $groupRepository->save(new \App\Entity\Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $this->createUser($userRepository, 'alice@rehearsalbox.test', UserRole::Musicien);

        $request = new Request('POST', "/api/admin/groups/{$group->id()}/members", [], ['email' => 'alice@rehearsalbox.test'], []);
        $response = $controller->addMember($request, (string) $group->id());

        self::assertSame(200, $response->statusCode());
    }

    #[Test]

    public function testAddMemberWithUnknownEmailReturns422(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');
        $group = $groupRepository->save(new \App\Entity\Group(0, 'Groupe Test', null, null, 'contact@example.test'));

        $request = new Request('POST', "/api/admin/groups/{$group->id()}/members", [], ['email' => 'inconnu@rehearsalbox.test'], []);
        $response = $controller->addMember($request, (string) $group->id());

        self::assertSame(422, $response->statusCode());
    }

    #[Test]

    public function testRemoveMemberReturns204(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');
        $group = $groupRepository->save(new \App\Entity\Group(0, 'Groupe Test', null, null, 'contact@example.test'));
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
