<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\SlotApiController;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Http\Request;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\Exception\AccessDeniedException;
use App\Security\NativePasswordHasher;
use App\Service\AuthService;
use App\Service\SlotService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;

final class SlotApiControllerTest extends RepositoryTestCase
{
    private function makeController(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $slotRepository = new MysqlRecurringSlotRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $session = new InMemorySession();
        $authService = new AuthService($userRepository, new NativePasswordHasher(), $session);
        $authGuard = new AuthGuard($authService);
        $slotService = new SlotService($slotRepository);

        $controller = new SlotApiController($slotService, $authGuard);

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

    public function testIndexReturnsAllActiveSlots(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null));
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');

        $controller->store(new Request('POST', '/api/admin/slots', [], [
            'groupId' => $group->id(), 'weekday' => 1, 'startTime' => '18:00:00', 'endTime' => '20:00:00',
        ], []));

        $response = $controller->index(new Request('GET', '/api/admin/slots', [], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertCount(1, json_decode($response->body(), true)['slots']);
    }

    public function testStoreByAdminReturns201(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null));
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');

        $request = new Request('POST', '/api/admin/slots', [], [
            'groupId' => $group->id(),
            'weekday' => 1,
            'startTime' => '18:00:00',
            'endTime' => '20:00:00',
        ], []);

        $response = $controller->store($request);

        self::assertSame(201, $response->statusCode());
    }

    public function testStoreByNonAdminThrowsAccessDenied(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null));
        $this->createUser($userRepository, 'musicien@rehearsalbox.test', UserRole::Musicien);
        $authService->attempt('musicien@rehearsalbox.test', 'password');

        $this->expectException(AccessDeniedException::class);

        $request = new Request('POST', '/api/admin/slots', [], [
            'groupId' => $group->id(),
            'weekday' => 1,
            'startTime' => '18:00:00',
            'endTime' => '20:00:00',
        ], []);
        $controller->store($request);
    }

    public function testStoreWithOverlappingSlotReturns422(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null));
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');

        $controller->store(new Request('POST', '/api/admin/slots', [], [
            'groupId' => $group->id(), 'weekday' => 1, 'startTime' => '18:00:00', 'endTime' => '20:00:00',
        ], []));

        $response = $controller->store(new Request('POST', '/api/admin/slots', [], [
            'groupId' => $group->id(), 'weekday' => 1, 'startTime' => '19:00:00', 'endTime' => '21:00:00',
        ], []));

        self::assertSame(422, $response->statusCode());
    }

    public function testUpdateByAdminReturns200(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null));
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');

        $created = $controller->store(new Request('POST', '/api/admin/slots', [], [
            'groupId' => $group->id(), 'weekday' => 1, 'startTime' => '18:00:00', 'endTime' => '20:00:00',
        ], []));
        $slotId = json_decode($created->body(), true)['id'];

        $response = $controller->update(
            new Request('PATCH', "/api/admin/slots/{$slotId}", [], ['startTime' => '19:00:00', 'endTime' => '21:00:00'], []),
            (string) $slotId,
        );

        self::assertSame(200, $response->statusCode());
    }

    public function testDestroyByAdminReturns204(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null));
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');

        $created = $controller->store(new Request('POST', '/api/admin/slots', [], [
            'groupId' => $group->id(), 'weekday' => 1, 'startTime' => '18:00:00', 'endTime' => '20:00:00',
        ], []));
        $slotId = json_decode($created->body(), true)['id'];

        $response = $controller->destroy(new Request('DELETE', "/api/admin/slots/{$slotId}", [], [], []), (string) $slotId);

        self::assertSame(204, $response->statusCode());
    }
}
