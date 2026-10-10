<?php

declare(strict_types=1);

namespace App\Tests\Account\Controller\Api;

use App\Account\Controller\Api\UserAdminApiController;
use App\Account\Entity\UserRole;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Http\Request;
use App\Group\Repository\MysqlGroupRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\Exception\AccessDeniedException;
use App\Security\Exception\UnauthenticatedException;
use App\Tests\Doubles\FastPasswordHasher;
use App\Account\Security\PasswordPolicy;
use App\Account\Service\AuthService;
use App\Account\Service\UserAdminService;
use App\Account\Service\UserProvisioningService;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\InMemorySession;
use App\Tests\Scenarios\KernelTranslation;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class UserAdminApiControllerTest extends RepositoryTestCase
{
    private MysqlUserRepository $users;
    private MysqlGroupRepository $groups;
    private AuthService $auth;
    /** Le contrôleur tel que le sert le Kernel : une exception métier devient sa réponse (KernelTranslation). */
    private KernelTranslation $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->groups = new MysqlGroupRepository($this->pdo);
        $hasher = new FastPasswordHasher();
        $this->auth = new AuthService($this->users, $hasher, new InMemorySession(), $this->groups);
        $transactions = new \App\Database\TransactionRunner($this->pdo);
        $accounts = new \App\Account\Service\UserAccountAdminService(
            $this->users,
            new \App\Account\Service\EmailChangeService($this->users, new \App\Account\Repository\MysqlEmailChangeRepository($this->pdo), new \App\Account\Repository\MysqlPasswordResetRepository($this->pdo), $hasher, \App\Tests\Scenarios\TestMailbox::of(new \App\Tests\Doubles\RecordingMailer()), $transactions),
            new \App\Account\Security\LastAdminGuard($this->users),
            new \App\Account\Security\DisplayNamePolicy(),
            $transactions,
            new \Psr\Log\NullLogger(),
        );
        $this->controller = new KernelTranslation(new UserAdminApiController(
            new UserAdminService($this->users, $this->groups, new UserProvisioningService($this->users, $hasher, new PasswordPolicy()), \App\Tests\Support\TestLoginThrottle::make($this->pdo), new \App\Account\Security\LastAdminGuard($this->users), $transactions),
            new AuthGuard($this->auth),
            $accounts,
        ));
    }

    private function user(string $email, UserRole $role = UserRole::Musicien, bool $active = true): User
    {
        return $this->users->save(new User(0, $email, password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]), $email, $role, $active, 0, null));
    }

    private function loginAsAdmin(): User
    {
        $admin = $this->user('admin@rehearsalbox.test', UserRole::Admin);
        $this->auth->attempt('admin@rehearsalbox.test', 'password');

        return $admin;
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, string $path, array $body = []): Request
    {
        return new Request($method, $path, [], $body, []);
    }

    /** @return array<string, mixed> */
    private function json(\App\Http\Response $response): array
    {
        return json_decode($response->body(), true);
    }

    // --- Accès ---------------------------------------------------------------

    #[Test]
    public function testEveryRouteRefusesAnAnonymousVisitor(): void
    {
        foreach ([
            fn () => $this->controller->index($this->request('GET', '/api/admin/users')),
            fn () => $this->controller->store($this->request('POST', '/api/admin/users', ['email' => 'a@b.test', 'displayName' => 'A', 'role' => 'musicien'])),
            fn () => $this->controller->update($this->request('PATCH', '/api/admin/users/1', ['active' => false]), '1'),
            fn () => $this->controller->unlock($this->request('POST', '/api/admin/users/1/unlock'), '1'),
            fn () => $this->controller->updateIdentity($this->request('PUT', '/api/admin/users/1/identity', ['displayName' => 'A', 'email' => 'a@b.test']), '1'),
            fn () => $this->controller->updateRole($this->request('PUT', '/api/admin/users/1/role', ['role' => 'admin']), '1'),
        ] as $call) {
            try {
                $call();
                self::fail('UnauthenticatedException attendue.');
            } catch (UnauthenticatedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testEveryRouteRefusesANonAdmin(): void
    {
        $this->user('musicien@rehearsalbox.test');
        $this->auth->attempt('musicien@rehearsalbox.test', 'password');

        foreach ([
            fn () => $this->controller->index($this->request('GET', '/api/admin/users')),
            fn () => $this->controller->store($this->request('POST', '/api/admin/users', ['email' => 'a@b.test', 'displayName' => 'A', 'role' => 'musicien'])),
            fn () => $this->controller->update($this->request('PATCH', '/api/admin/users/1', ['active' => false]), '1'),
            fn () => $this->controller->unlock($this->request('POST', '/api/admin/users/1/unlock'), '1'),
            fn () => $this->controller->updateIdentity($this->request('PUT', '/api/admin/users/1/identity', ['displayName' => 'A', 'email' => 'a@b.test']), '1'),
            fn () => $this->controller->updateRole($this->request('PUT', '/api/admin/users/1/role', ['role' => 'admin']), '1'),
        ] as $call) {
            try {
                $call();
                self::fail('AccessDeniedException attendue.');
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertNull($this->users->findByEmail('a@b.test'), 'aucun compte créé par un non-admin');
    }

    // --- Liste ------------------------------------------------------------------

    #[Test]
    public function testIndexListsAccountsWithGroupsAndNeverExposesPasswordHashes(): void
    {
        $this->loginAsAdmin();
        $alice = $this->user('alice@rehearsalbox.test');
        $group = $this->groups->save(new Group(0, 'Rock', null, null, 'c@example.test'));
        $this->groups->addMember($group->id(), $alice->id());

        $response = $this->controller->index($this->request('GET', '/api/admin/users'));
        $body = $this->json($response);

        self::assertSame(200, $response->statusCode());
        self::assertCount(2, $body['users']);
        $aliceRow = array_values(array_filter($body['users'], static fn (array $u): bool => $u['email'] === 'alice@rehearsalbox.test'))[0];
        self::assertSame('musicien', $aliceRow['role']);
        self::assertTrue($aliceRow['isActive']);
        self::assertFalse($aliceRow['isLocked']);
        self::assertSame([['id' => $group->id(), 'name' => 'Rock', 'role' => 'membre']], $aliceRow['groups']);
        self::assertStringNotContainsString('password', strtolower($response->body()));
        self::assertStringNotContainsString('$2y$', $response->body());
    }

    // --- Création ------------------------------------------------------------------

    #[Test]
    public function testStoreCreatesAnAccountAndReturns201WithoutAnyPassword(): void
    {
        $this->loginAsAdmin();
        $group = $this->groups->save(new Group(0, 'The Office', null, null, 'c@example.test'));

        $response = $this->controller->store($this->request('POST', '/api/admin/users', [
            'email' => 'younasse@rehearsalbox.test', 'displayName' => 'Younasse', 'role' => 'musicien', 'groupId' => $group->id(),
        ]));

        self::assertSame(201, $response->statusCode());
        $created = $this->users->findByEmail('younasse@rehearsalbox.test');
        self::assertNotNull($created);
        self::assertTrue($this->groups->isMember($group->id(), $created->id()));
        self::assertStringNotContainsString('password', strtolower($response->body()));
        self::assertSame($created->id(), $this->json($response)['id']);
    }

    #[Test]
    public function testStoreIgnoresAnyPasswordSentByTheClient(): void
    {
        $this->loginAsAdmin();
        $sent = 'Pw-' . bin2hex(random_bytes(6));

        $this->controller->store($this->request('POST', '/api/admin/users', [
            'email' => 'x@rehearsalbox.test', 'displayName' => 'X', 'role' => 'musicien', 'password' => $sent,
        ]));

        $created = $this->users->findByEmail('x@rehearsalbox.test');
        self::assertFalse((new FastPasswordHasher())->verify($sent, $created->passwordHash()), "l'admin ne fixe aucun mot de passe");
    }

    #[Test]
    public function testStoreReturns422WithFieldsForInvalidInput(): void
    {
        $this->loginAsAdmin();

        $response = $this->controller->store($this->request('POST', '/api/admin/users', ['email' => 'pas-un-email', 'displayName' => '', 'role' => 'musicien']));

        self::assertSame(422, $response->statusCode());
        $fields = $this->json($response)['fields'];
        self::assertArrayHasKey('email', $fields);
        self::assertArrayHasKey('displayName', $fields);
    }

    #[Test]
    public function testStoreReturns422ForAnUnknownRoleOrGroup(): void
    {
        $this->loginAsAdmin();

        $badRole = $this->controller->store($this->request('POST', '/api/admin/users', ['email' => 'a@rehearsalbox.test', 'displayName' => 'A', 'role' => 'superadmin']));
        $badGroup = $this->controller->store($this->request('POST', '/api/admin/users', ['email' => 'b@rehearsalbox.test', 'displayName' => 'B', 'role' => 'musicien', 'groupId' => 9999]));

        self::assertSame(422, $badRole->statusCode());
        self::assertArrayHasKey('role', $this->json($badRole)['fields']);
        self::assertSame(422, $badGroup->statusCode());
        self::assertArrayHasKey('groupId', $this->json($badGroup)['fields']);
        self::assertNull($this->users->findByEmail('a@rehearsalbox.test'));
        self::assertNull($this->users->findByEmail('b@rehearsalbox.test'));
    }

    #[Test]
    public function testStoreReturns422ForMalformedTypes(): void
    {
        $this->loginAsAdmin();

        foreach ([
            ['email' => ['a@b.test'], 'displayName' => 'A', 'role' => 'musicien'],
            ['email' => 'a@b.test', 'displayName' => ['A'], 'role' => 'musicien'],
            ['email' => 'a@b.test', 'displayName' => 'A', 'role' => ['musicien']],
            ['email' => 'a@b.test', 'displayName' => 'A', 'role' => 'musicien', 'groupId' => 'abc'],
        ] as $body) {
            self::assertSame(422, $this->controller->store($this->request('POST', '/api/admin/users', $body))->statusCode(), json_encode($body));
        }
    }

    // --- Activation ------------------------------------------------------------------

    #[Test]
    public function testUpdateDeactivatesAndReactivatesAnAccount(): void
    {
        $this->loginAsAdmin();
        $alice = $this->user('alice@rehearsalbox.test');

        $off = $this->controller->update($this->request('PATCH', "/api/admin/users/{$alice->id()}", ['active' => false]), (string) $alice->id());
        self::assertSame(200, $off->statusCode());
        self::assertFalse($this->users->findById($alice->id())->isActive());

        $on = $this->controller->update($this->request('PATCH', "/api/admin/users/{$alice->id()}", ['active' => true]), (string) $alice->id());
        self::assertSame(200, $on->statusCode());
        self::assertTrue($this->users->findById($alice->id())->isActive());
    }

    #[Test]
    public function testUpdateRefusesToDeactivateYourselfWithAReadableMessage(): void
    {
        $admin = $this->loginAsAdmin();
        $this->user('autre@rehearsalbox.test', UserRole::Admin);

        $response = $this->controller->update($this->request('PATCH', "/api/admin/users/{$admin->id()}", ['active' => false]), (string) $admin->id());

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('propre compte', $this->json($response)['error']);
        self::assertTrue($this->users->findById($admin->id())->isActive());
    }

    #[Test]
    public function testUpdateReturns404ForAnUnknownUserAnd422ForAMissingOrNonBooleanActiveFlag(): void
    {
        $this->loginAsAdmin();
        $alice = $this->user('alice@rehearsalbox.test');

        self::assertSame(404, $this->controller->update($this->request('PATCH', '/api/admin/users/9999', ['active' => false]), '9999')->statusCode());
        foreach ([[], ['active' => 'false'], ['active' => 1], ['active' => ['x']]] as $body) {
            self::assertSame(422, $this->controller->update($this->request('PATCH', "/api/admin/users/{$alice->id()}", $body), (string) $alice->id())->statusCode(), json_encode($body));
        }
        self::assertTrue($this->users->findById($alice->id())->isActive());
    }

    #[Test]
    public function testUpdateWithEdgeCaseIdsNeverCrashes(): void
    {
        $this->loginAsAdmin();

        foreach (['0', '-1', 'abc', '99999999999999999999', '1.5'] as $id) {
            self::assertContains($this->controller->update($this->request('PATCH', "/api/admin/users/{$id}", ['active' => false]), $id)->statusCode(), [404, 422], "id {$id}");
            self::assertContains($this->controller->unlock($this->request('POST', "/api/admin/users/{$id}/unlock"), $id)->statusCode(), [404, 422], "id {$id}");
        }
    }

    // --- Déblocage ----------------------------------------------------------------------

    #[Test]
    public function testUnlockClearsTheLock(): void
    {
        $this->loginAsAdmin();
        $alice = $this->user('alice@rehearsalbox.test');
        $this->users->save($alice->withLockedUntil(new \DateTimeImmutable('+7 days')));

        $response = $this->controller->unlock($this->request('POST', "/api/admin/users/{$alice->id()}/unlock"), (string) $alice->id());

        self::assertSame(200, $response->statusCode());
        self::assertNull($this->users->findById($alice->id())->lockedUntil());
    }

    #[Test]
    public function testUnlockReturns404ForAnUnknownUser(): void
    {
        $this->loginAsAdmin();

        self::assertSame(404, $this->controller->unlock($this->request('POST', '/api/admin/users/9999/unlock'), '9999')->statusCode());
    }

    // --- Identité et rôle (#272) -----------------------------------------------------------------------

    #[Test]
    public function testIdentityChangesNameAndAddressAndNeverReturnsAPasswordHash(): void
    {
        $this->loginAsAdmin();
        $alice = $this->user('alice@rehearsalbox.test');

        $response = $this->controller->updateIdentity($this->request('PUT', '/x', ['displayName' => 'Alice Martin', 'email' => 'nouvelle@rehearsalbox.test']), (string) $alice->id());

        self::assertSame(200, $response->statusCode());
        self::assertSame('Alice Martin', $this->json($response)['displayName']);
        self::assertSame('nouvelle@rehearsalbox.test', $this->json($response)['email']);
        self::assertStringNotContainsString('$2y$', $response->body());
    }

    #[Test]
    public function testIdentityAnswers422ForBadTypesAnInvalidOrUsedAddressAndAnInvalidName(): void
    {
        $this->loginAsAdmin();
        $alice = $this->user('alice@rehearsalbox.test');
        $this->user('bob@rehearsalbox.test');

        foreach ([
            [['displayName' => 12, 'email' => 'a@b.test'], 'displayName'],
            [['displayName' => 'Alice', 'email' => ['x']], 'email'],
            [['displayName' => 'Alice', 'email' => 'pas-une-adresse'], 'email'],
            [['displayName' => 'Alice', 'email' => 'bob@rehearsalbox.test'], 'email'],
            [['displayName' => '', 'email' => 'alice@rehearsalbox.test'], 'displayName'],
        ] as [$body, $field]) {
            $response = $this->controller->updateIdentity($this->request('PUT', '/x', $body), (string) $alice->id());
            self::assertSame(422, $response->statusCode(), json_encode($body));
            self::assertArrayHasKey($field, $this->json($response)['fields']);
        }
        self::assertSame('alice@rehearsalbox.test', $this->users->findById($alice->id())->email());
    }

    #[Test]
    public function testAnAdminCannotChangeTheirOwnAddressFromThisRouteAnd404ForUnknownOrMalformedIds(): void
    {
        $admin = $this->loginAsAdmin();

        $own = $this->controller->updateIdentity($this->request('PUT', '/x', ['displayName' => 'Chef', 'email' => 'autre@rehearsalbox.test']), (string) $admin->id());
        self::assertSame(422, $own->statusCode());
        self::assertSame('Modifiez votre propre adresse depuis Mon compte.', $this->json($own)['error']);

        foreach (['999999', '1.5', '-1', 'abc', '1e3', ''] as $id) {
            self::assertSame(404, $this->controller->updateIdentity($this->request('PUT', '/x', ['displayName' => 'A', 'email' => 'a@b.test']), $id)->statusCode(), "identité : {$id}");
            self::assertSame(404, $this->controller->updateRole($this->request('PUT', '/x', ['role' => 'admin']), $id)->statusCode(), "rôle : {$id}");
        }
    }

    #[Test]
    public function testRolePromotesDemotesAndRefusesAnInvalidRoleOrTheLastAdmin(): void
    {
        $admin = $this->loginAsAdmin();
        $alice = $this->user('alice@rehearsalbox.test');

        $promoted = $this->controller->updateRole($this->request('PUT', '/x', ['role' => 'admin']), (string) $alice->id());
        self::assertSame('admin', $this->json($promoted)['role']);
        $demoted = $this->controller->updateRole($this->request('PUT', '/x', ['role' => 'musicien']), (string) $alice->id());
        self::assertSame('musicien', $this->json($demoted)['role']);

        foreach ([['role' => 'root'], ['role' => 3], []] as $body) {
            $response = $this->controller->updateRole($this->request('PUT', '/x', $body), (string) $alice->id());
            self::assertSame(422, $response->statusCode());
            self::assertArrayHasKey('role', $this->json($response)['fields']);
        }

        $self = $this->controller->updateRole($this->request('PUT', '/x', ['role' => 'musicien']), (string) $admin->id());
        self::assertSame(422, $self->statusCode());
        self::assertSame('admin', $this->users->findById($admin->id())->role()->value);
    }
}
