<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\AuthApiController;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Http\Request;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Security\NativePasswordHasher;
use App\Service\AuthService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;
use PHPUnit\Framework\Attributes\Test;

final class AuthApiControllerTest extends RepositoryTestCase
{
    private function makeController(): array
    {
        $userRepository = new MysqlUserRepository($this->pdo);
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $hasher = new NativePasswordHasher();
        $session = new InMemorySession();
        $authService = new AuthService($userRepository, $hasher, $session, $groupRepository);
        $controller = new AuthApiController($authService, $userRepository, $hasher);

        return [$controller, $userRepository, $session, $groupRepository];
    }

    #[Test]

    public function testRegisterCreatesUserAndReturns201(): void
    {
        [$controller] = $this->makeController();

        $request = new Request('POST', '/api/auth/register', [], [
            'email' => 'alice@rehearsalbox.test',
            'password' => 'password123',
            'displayName' => 'Alice',
        ], []);

        $response = $controller->register($request);

        self::assertSame(201, $response->statusCode());
    }

    #[Test]

    public function testRegisterWithInvalidEmailReturns422(): void
    {
        [$controller] = $this->makeController();

        $request = new Request('POST', '/api/auth/register', [], [
            'email' => 'pas-un-email',
            'password' => 'password123',
            'displayName' => 'Alice',
        ], []);

        $response = $controller->register($request);

        self::assertSame(422, $response->statusCode());
    }

    #[Test]

    public function testRegisterWithShortPasswordReturns422(): void
    {
        [$controller] = $this->makeController();

        $request = new Request('POST', '/api/auth/register', [], [
            'email' => 'bob@rehearsalbox.test',
            'password' => '123',
            'displayName' => 'Bob',
        ], []);

        $response = $controller->register($request);

        self::assertSame(422, $response->statusCode());
    }

    #[Test]

    public function testRegisterWithDuplicateEmailReturns422(): void
    {
        [$controller, $userRepository] = $this->makeController();
        $userRepository->save(new User(
            0,
            'chris@rehearsalbox.test',
            (new NativePasswordHasher())->hash('password123'),
            'Chris',
            UserRole::Musicien,
            true,
            0,
            null,
        ));

        $request = new Request('POST', '/api/auth/register', [], [
            'email' => 'chris@rehearsalbox.test',
            'password' => 'password123',
            'displayName' => 'Chris Bis',
        ], []);

        $response = $controller->register($request);

        self::assertSame(422, $response->statusCode());
    }

    #[Test]

    public function testLoginWithValidCredentialsReturns200(): void
    {
        [$controller, $userRepository] = $this->makeController();
        $userRepository->save(new User(
            0,
            'dana@rehearsalbox.test',
            (new NativePasswordHasher())->hash('password123'),
            'Dana',
            UserRole::Musicien,
            true,
            0,
            null,
        ));

        $request = new Request('POST', '/api/auth/login', [], [
            'email' => 'dana@rehearsalbox.test',
            'password' => 'password123',
        ], []);

        $response = $controller->login($request);

        self::assertSame(200, $response->statusCode());
    }

    #[Test]

    public function testLoginWithInvalidCredentialsReturns401(): void
    {
        [$controller] = $this->makeController();

        $request = new Request('POST', '/api/auth/login', [], [
            'email' => 'inconnu@rehearsalbox.test',
            'password' => 'peu-importe',
        ], []);

        $response = $controller->login($request);

        self::assertSame(401, $response->statusCode());
    }

    #[Test]

    public function testLogoutReturns200(): void
    {
        [$controller] = $this->makeController();

        $response = $controller->logout(new Request('POST', '/api/auth/logout', [], [], []));

        self::assertSame(200, $response->statusCode());
    }

    #[Test]

    public function testLoginWithSingleGroupDoesNotRequireSelection(): void
    {
        [$controller, $userRepository, , $groupRepository] = $this->makeController();
        $user = $userRepository->save(new User(
            0,
            'kim@rehearsalbox.test',
            (new NativePasswordHasher())->hash('password123'),
            'Kim',
            UserRole::Musicien,
            true,
            0,
            null,
        ));
        $group = $groupRepository->save(new Group(0, 'Groupe Solo', null, null, 'contact@example.test'));
        $groupRepository->addMember($group->id(), $user->id());

        $request = new Request('POST', '/api/auth/login', [], ['email' => 'kim@rehearsalbox.test', 'password' => 'password123'], []);
        $response = $controller->login($request);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->statusCode());
        self::assertArrayNotHasKey('groupsToSelect', $body);
    }

    #[Test]

    public function testLoginWithMultipleGroupsRequiresSelection(): void
    {
        [$controller, $userRepository, , $groupRepository] = $this->makeController();
        $user = $userRepository->save(new User(
            0,
            'liam@rehearsalbox.test',
            (new NativePasswordHasher())->hash('password123'),
            'Liam',
            UserRole::Musicien,
            true,
            0,
            null,
        ));
        $groupA = $groupRepository->save(new Group(0, 'Groupe A', null, null, 'contact@example.test'));
        $groupB = $groupRepository->save(new Group(0, 'Groupe B', null, null, 'contact@example.test'));
        $groupRepository->addMember($groupA->id(), $user->id());
        $groupRepository->addMember($groupB->id(), $user->id());

        $request = new Request('POST', '/api/auth/login', [], ['email' => 'liam@rehearsalbox.test', 'password' => 'password123'], []);
        $response = $controller->login($request);
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->statusCode());
        self::assertCount(2, $body['groupsToSelect']);
    }

    #[Test]

    public function testSelectGroupWithMembershipReturns200(): void
    {
        [$controller, $userRepository, , $groupRepository] = $this->makeController();
        $user = $userRepository->save(new User(
            0,
            'mona@rehearsalbox.test',
            (new NativePasswordHasher())->hash('password123'),
            'Mona',
            UserRole::Musicien,
            true,
            0,
            null,
        ));
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $groupRepository->addMember($group->id(), $user->id());
        $controller->login(new Request('POST', '/api/auth/login', [], ['email' => 'mona@rehearsalbox.test', 'password' => 'password123'], []));

        $response = $controller->selectGroup(new Request('POST', '/api/auth/select-group', [], ['groupId' => $group->id()], []));

        self::assertSame(200, $response->statusCode());
    }

    #[Test]

    public function testSelectGroupWithoutMembershipReturns403(): void
    {
        [$controller, $userRepository, , $groupRepository] = $this->makeController();
        $user = $userRepository->save(new User(
            0,
            'noe@rehearsalbox.test',
            (new NativePasswordHasher())->hash('password123'),
            'Noe',
            UserRole::Musicien,
            true,
            0,
            null,
        ));
        $otherGroup = $groupRepository->save(new Group(0, 'Groupe Tiers', null, null, 'contact@example.test'));
        $controller->login(new Request('POST', '/api/auth/login', [], ['email' => 'noe@rehearsalbox.test', 'password' => 'password123'], []));

        $response = $controller->selectGroup(new Request('POST', '/api/auth/select-group', [], ['groupId' => $otherGroup->id()], []));

        self::assertSame(403, $response->statusCode());
    }
}
