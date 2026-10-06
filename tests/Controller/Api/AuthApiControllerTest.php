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
use App\Tests\Support\FastPasswordHasher;
use App\Repository\MysqlThrottleEventRepository;
use App\Security\Exception\AccessDeniedException;
use App\Service\AuthService;
use App\Service\IpThrottle;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class AuthApiControllerTest extends RepositoryTestCase
{
    private const THROTTLE_LIMIT = 5;

    private function makeController(): array
    {
        $userRepository = new MysqlUserRepository($this->pdo);
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $hasher = new FastPasswordHasher();
        $session = new InMemorySession();
        $authService = new AuthService($userRepository, $hasher, $session, $groupRepository);
        $controller = new AuthApiController($authService, new IpThrottle(new MysqlThrottleEventRepository($this->pdo), 'login', self::THROTTLE_LIMIT, '-15 minutes'));

        return [$controller, $userRepository, $session, $groupRepository];
    }

    #[Test]

    public function testLoginWithValidCredentialsReturns200(): void
    {
        [$controller, $userRepository] = $this->makeController();
        $userRepository->save(new User(
            0,
            'dana@rehearsalbox.test',
            (new FastPasswordHasher())->hash('password123'),
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
            (new FastPasswordHasher())->hash('password123'),
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
            (new FastPasswordHasher())->hash('password123'),
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
            (new FastPasswordHasher())->hash('password123'),
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

    public function testSelectGroupWithoutMembershipIsRefused(): void
    {
        [$controller, $userRepository, , $groupRepository] = $this->makeController();
        $user = $userRepository->save(new User(
            0,
            'noe@rehearsalbox.test',
            (new FastPasswordHasher())->hash('password123'),
            'Noe',
            UserRole::Musicien,
            true,
            0,
            null,
        ));
        $otherGroup = $groupRepository->save(new Group(0, 'Groupe Tiers', null, null, 'contact@example.test'));
        $controller->login(new Request('POST', '/api/auth/login', [], ['email' => 'noe@rehearsalbox.test', 'password' => 'password123'], []));

        // L'accès refusé remonte : c'est le Kernel qui en fait la réponse 403 (voir KernelTest).
        $this->expectException(AccessDeniedException::class);
        $controller->selectGroup(new Request('POST', '/api/auth/select-group', [], ['groupId' => $otherGroup->id()], []));
    }

    #[Test]
    public function testSelectGroupRefusesAMalformedIdentifierInsteadOfTruncatingIt(): void
    {
        [$controller, $userRepository, , $groupRepository] = $this->makeController();
        $user = $userRepository->save(new User(0, 'mona@rehearsalbox.test', (new FastPasswordHasher())->hash('password123'), 'Mona', UserRole::Musicien, true, 0, null));
        $group = $groupRepository->save(new Group(0, 'Groupe Mona', null, null, 'contact@example.test'));
        $groupRepository->addMember($group->id(), $user->id());
        $controller->login(new Request('POST', '/api/auth/login', [], ['email' => 'mona@rehearsalbox.test', 'password' => 'password123'], []));

        // « 12abc » ne doit pas devenir 12 (ici l'identifiant réel du groupe suivi de bruit).
        $this->expectException(AccessDeniedException::class);
        $controller->selectGroup(new Request('POST', '/api/auth/select-group', [], ['groupId' => $group->id() . 'abc'], []));
    }

    private function loginFrom(AuthApiController $controller, string $ip, string $email, string $password): \App\Http\JsonResponse
    {
        return $controller->login(new Request('POST', '/api/auth/login', [], ['email' => $email, 'password' => $password], [], [], $ip));
    }

    #[Test]
    public function testAnAddressThatKeepsFailingIsRefusedEvenWithTheRightPassword(): void
    {
        [$controller, $userRepository] = $this->makeController();
        $userRepository->save(new User(0, 'dana@rehearsalbox.test', (new FastPasswordHasher())->hash('password123'), 'Dana', UserRole::Musicien, true, 0, null));
        // Des comptes DIFFÉRENTS (dont un inconnu) : la limite est par adresse, aucun compte n'est verrouillé.
        for ($i = 0; $i < self::THROTTLE_LIMIT; ++$i) {
            self::assertSame(401, $this->loginFrom($controller, '203.0.113.7', "personne{$i}@rehearsalbox.test", 'faux')->statusCode());
        }

        $blocked = $this->loginFrom($controller, '203.0.113.7', 'dana@rehearsalbox.test', 'password123');

        self::assertSame(429, $blocked->statusCode());
        self::assertSame('900', $blocked->headers()['Retry-After']);
        self::assertSame(0, $userRepository->findByEmail('dana@rehearsalbox.test')->failedLoginAttempts(), 'aucun compte touché par la limite');
    }

    #[Test]
    public function testAnotherAddressStillSignsInNormally(): void
    {
        [$controller, $userRepository] = $this->makeController();
        $userRepository->save(new User(0, 'dana@rehearsalbox.test', (new FastPasswordHasher())->hash('password123'), 'Dana', UserRole::Musicien, true, 0, null));
        for ($i = 0; $i < self::THROTTLE_LIMIT; ++$i) {
            $this->loginFrom($controller, '203.0.113.7', "personne{$i}@rehearsalbox.test", 'faux');
        }
        self::assertSame(429, $this->loginFrom($controller, '203.0.113.7', 'dana@rehearsalbox.test', 'password123')->statusCode());

        self::assertSame(200, $this->loginFrom($controller, '198.51.100.9', 'dana@rehearsalbox.test', 'password123')->statusCode());
    }

    #[Test]
    public function testSuccessfulLoginsNeverCountAgainstTheAddress(): void
    {
        [$controller, $userRepository] = $this->makeController();
        $userRepository->save(new User(0, 'dana@rehearsalbox.test', (new FastPasswordHasher())->hash('password123'), 'Dana', UserRole::Musicien, true, 0, null));

        for ($i = 0; $i < self::THROTTLE_LIMIT + 5; ++$i) {
            self::assertSame(200, $this->loginFrom($controller, '203.0.113.7', 'dana@rehearsalbox.test', 'password123')->statusCode());
        }
    }
}
