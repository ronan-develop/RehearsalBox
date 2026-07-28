<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\GroupSpaceApiController;
use App\Entity\Enum\GroupUserRole;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Http\Request;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\NativePasswordHasher;
use App\Service\AuthService;
use App\Service\GroupService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;

final class GroupSpaceApiControllerTest extends RepositoryTestCase
{
    private function makeController(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $session = new InMemorySession();
        $authService = new AuthService($userRepository, new NativePasswordHasher(), $session, $groupRepository);
        $authGuard = new AuthGuard($authService);
        $groupService = new GroupService($groupRepository, $userRepository);

        $controller = new GroupSpaceApiController($groupService, $groupRepository, $authGuard);

        return [$controller, $groupRepository, $userRepository, $authService];
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

    public function testShowByMemberReturns200(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $user = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $user->id());
        $authService->attempt('alice@rehearsalbox.test', 'password');

        $response = $controller->show(new Request('GET', "/api/groups/{$group->id()}/space", [], [], []), (string) $group->id());

        self::assertSame(200, $response->statusCode());
    }

    public function testShowByNonMemberReturns403(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $authService->attempt('bob@rehearsalbox.test', 'password');

        $response = $controller->show(new Request('GET', "/api/groups/{$group->id()}/space", [], [], []), (string) $group->id());

        self::assertSame(403, $response->statusCode());
    }

    public function testUpdateProfileByGestionnaireReturns200(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'chris@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $authService->attempt('chris@rehearsalbox.test', 'password');

        $request = new Request('PATCH', "/api/groups/{$group->id()}/space", [], [
            'lineup' => [['name' => 'Alice', 'instrument' => 'Guitare']],
            'upcomingShows' => [['date' => '2026-09-12', 'venue' => 'Le Point Éphémère']],
        ], []);
        $response = $controller->updateProfile($request, (string) $group->id());
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->statusCode());
        self::assertCount(1, $body['lineup']);
    }

    public function testUpdateProfileByNonGestionnaireReturns403(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $member = $this->createUser($userRepository, 'dana@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $member->id());
        $authService->attempt('dana@rehearsalbox.test', 'password');

        $request = new Request('PATCH', "/api/groups/{$group->id()}/space", [], ['lineup' => [], 'upcomingShows' => []], []);
        $response = $controller->updateProfile($request, (string) $group->id());

        self::assertSame(403, $response->statusCode());
    }
}
