<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\AuthApiController;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Http\Request;
use App\Repository\MysqlUserRepository;
use App\Security\NativePasswordHasher;
use App\Service\AuthService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;

final class AuthApiControllerTest extends RepositoryTestCase
{
    private function makeController(): array
    {
        $userRepository = new MysqlUserRepository($this->pdo);
        $hasher = new NativePasswordHasher();
        $session = new InMemorySession();
        $authService = new AuthService($userRepository, $hasher, $session);
        $controller = new AuthApiController($authService, $userRepository, $hasher);

        return [$controller, $userRepository, $session];
    }

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

    public function testLogoutReturns200(): void
    {
        [$controller] = $this->makeController();

        $response = $controller->logout(new Request('POST', '/api/auth/logout', [], [], []));

        self::assertSame(200, $response->statusCode());
    }
}
