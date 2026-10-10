<?php

declare(strict_types=1);

namespace App\Tests\Group\Controller\Api;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Repository\MysqlUserRepository;
use App\Account\Service\AuthService;
use App\Database\TransactionRunner;
use App\Group\Controller\Api\UserGroupAdminApiController;
use App\Group\Entity\Group;
use App\Group\Entity\GroupUserRole;
use App\Group\Repository\MysqlGroupManagerRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Group\Service\GroupManagerService;
use App\Group\Service\GroupMembershipAdminService;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Security\Exception\AccessDeniedException;
use App\Security\Exception\UnauthenticatedException;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\FastPasswordHasher;
use App\Tests\Doubles\InMemorySession;
use App\Tests\Scenarios\KernelTranslation;
use PHPUnit\Framework\Attributes\Test;

/** #272 : routes d'appartenance d'un compte aux groupes : administrateur seulement, identifiants stricts, gardes du service reflétées en 422. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class UserGroupAdminApiControllerTest extends RepositoryTestCase
{
    private MysqlUserRepository $users;
    private MysqlGroupRepository $groups;
    private MysqlGroupManagerRepository $managers;
    private AuthService $auth;
    private KernelTranslation $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->groups = new MysqlGroupRepository($this->pdo);
        $this->managers = new MysqlGroupManagerRepository($this->pdo);
        $this->auth = new AuthService($this->users, new FastPasswordHasher(), new InMemorySession(), $this->groups);
        $this->controller = new KernelTranslation(new UserGroupAdminApiController(
            new GroupMembershipAdminService($this->users, $this->groups, $this->managers, new GroupManagerService($this->groups, $this->managers), new TransactionRunner($this->pdo), new \Psr\Log\NullLogger()),
            new AuthGuard($this->auth),
        ));
    }

    private function user(string $email, UserRole $role = UserRole::Musicien): User
    {
        return $this->users->save(new User(0, $email, password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]), $email, $role, true, 0, null));
    }

    private function loginAsAdmin(): void
    {
        $this->user('admin@rehearsalbox.test', UserRole::Admin);
        $this->auth->attempt('admin@rehearsalbox.test', 'password');
    }

    private function group(string $name): Group
    {
        return $this->groups->save(new Group(0, $name, null, null, strtolower($name) . '@example.test'));
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, array $body = []): Request
    {
        return new Request($method, '/x', [], $body, []);
    }

    /** @return list<callable(): mixed> */
    private function allCalls(): array
    {
        return [
            fn () => $this->controller->set($this->request('PUT', ['role' => 'membre']), '1', '1'),
            fn () => $this->controller->remove($this->request('DELETE'), '1', '1'),
            fn () => $this->controller->move($this->request('POST', ['toGroupId' => 2]), '1', '1'),
        ];
    }

    #[Test]
    public function testEveryRouteRefusesAnAnonymousVisitorAndANonAdmin(): void
    {
        foreach ($this->allCalls() as $call) {
            try {
                $call();
                self::fail('Refus attendu');
            } catch (UnauthenticatedException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->user('musicien@rehearsalbox.test');
        $this->auth->attempt('musicien@rehearsalbox.test', 'password');
        foreach ($this->allCalls() as $call) {
            try {
                $call();
                self::fail('Refus attendu');
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testSetAddsToAGroupThenChangesTheRole(): void
    {
        $this->loginAsAdmin();
        $alice = $this->user('alice@rehearsalbox.test');
        $alpha = $this->group('Alpha');

        self::assertSame(204, $this->controller->set($this->request('PUT', ['role' => 'membre']), (string) $alice->id(), (string) $alpha->id())->statusCode());
        self::assertSame(GroupUserRole::Membre, $this->groups->roleOf($alpha->id(), $alice->id()));
        self::assertSame(204, $this->controller->set($this->request('PUT', ['role' => 'gestionnaire']), (string) $alice->id(), (string) $alpha->id())->statusCode());
        self::assertSame(GroupUserRole::Gestionnaire, $this->groups->roleOf($alpha->id(), $alice->id()));
    }

    #[Test]
    public function testSetRefusesAnInvalidRoleAndAnUnknownGroupAndAnUnknownUser(): void
    {
        $this->loginAsAdmin();
        $alice = $this->user('alice@rehearsalbox.test');
        $alpha = $this->group('Alpha');

        foreach (['root', 3, null] as $role) {
            $response = $this->controller->set($this->request('PUT', ['role' => $role]), (string) $alice->id(), (string) $alpha->id());
            self::assertSame(422, $response->statusCode());
            self::assertArrayHasKey('role', json_decode($response->body(), true)['fields']);
        }
        self::assertSame(422, $this->controller->set($this->request('PUT', ['role' => 'membre']), (string) $alice->id(), '999999')->statusCode());
        self::assertSame(404, $this->controller->set($this->request('PUT', ['role' => 'membre']), '999999', (string) $alpha->id())->statusCode());
        self::assertNull($this->groups->roleOf($alpha->id(), $alice->id()));
    }

    #[Test]
    public function testRemoveTakesTheAccountOutOfTheGroupButNeverTheLastManager(): void
    {
        $this->loginAsAdmin();
        $alice = $this->user('alice@rehearsalbox.test');
        $bob = $this->user('bob@rehearsalbox.test');
        $alpha = $this->group('Alpha');
        $this->groups->addMember($alpha->id(), $alice->id());
        $this->groups->addMember($alpha->id(), $bob->id());
        $this->managers->promoteToManager($alpha->id(), $alice->id());

        $refused = $this->controller->remove($this->request('DELETE'), (string) $alice->id(), (string) $alpha->id());
        self::assertSame(422, $refused->statusCode());
        self::assertStringContainsString('dernier gestionnaire', json_decode($refused->body(), true)['error']);
        self::assertTrue($this->groups->isMember($alpha->id(), $alice->id()));

        self::assertSame(204, $this->controller->remove($this->request('DELETE'), (string) $bob->id(), (string) $alpha->id())->statusCode());
        self::assertFalse($this->groups->isMember($alpha->id(), $bob->id()));
    }

    #[Test]
    public function testMoveChangesGroupAndRefusesAMalformedDestination(): void
    {
        $this->loginAsAdmin();
        $alice = $this->user('alice@rehearsalbox.test');
        $alpha = $this->group('Alpha');
        $beta = $this->group('Beta');
        $this->groups->addMember($alpha->id(), $alice->id());

        foreach ([null, '1.5', -1, 'abc', []] as $to) {
            $response = $this->controller->move($this->request('POST', ['toGroupId' => $to]), (string) $alice->id(), (string) $alpha->id());
            self::assertSame(422, $response->statusCode(), json_encode($to));
        }
        self::assertTrue($this->groups->isMember($alpha->id(), $alice->id()));

        self::assertSame(204, $this->controller->move($this->request('POST', ['toGroupId' => $beta->id()]), (string) $alice->id(), (string) $alpha->id())->statusCode());
        self::assertFalse($this->groups->isMember($alpha->id(), $alice->id()));
        self::assertTrue($this->groups->isMember($beta->id(), $alice->id()));
    }

    #[Test]
    public function testMalformedIdentifiersInTheUrlAreRefused(): void
    {
        $this->loginAsAdmin();

        foreach (['1.5', '-1', 'abc', '1e3', '0', ''] as $bad) {
            foreach ([[$bad, '1'], ['1', $bad]] as [$user, $group]) {
                foreach ([
                    fn () => $this->controller->set($this->request('PUT', ['role' => 'membre']), $user, $group),
                    fn () => $this->controller->remove($this->request('DELETE'), $user, $group),
                    fn () => $this->controller->move($this->request('POST', ['toGroupId' => 2]), $user, $group),
                ] as $call) {
                    try {
                        $call();
                        self::fail("identifiant refusé attendu : {$bad}");
                    } catch (AccessDeniedException) {
                        $this->addToAssertionCount(1);
                    }
                }
            }
        }
    }
}
