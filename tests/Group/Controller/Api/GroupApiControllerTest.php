<?php

declare(strict_types=1);

namespace App\Tests\Group\Controller\Api;

use App\Group\Controller\Api\GroupApiController;
use App\Account\Entity\UserRole;
use App\Account\Entity\User;
use App\Http\Request;
use App\Group\Repository\MysqlGroupRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\Exception\AccessDeniedException;
use App\Tests\Doubles\FastPasswordHasher;
use App\Tests\Scenarios\KernelTranslation;
use App\Account\Service\AuthService;
use App\Group\Service\GroupService;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\InMemorySession;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class GroupApiControllerTest extends RepositoryTestCase
{
    private function makeController(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $session = new InMemorySession();
        $authService = new AuthService($userRepository, new FastPasswordHasher(), $session, $groupRepository);
        $authGuard = new AuthGuard($authService);
        $groupService = new GroupService($groupRepository, $userRepository);

        // Le contrôleur tel que le sert le Kernel : une exception métier devient sa réponse (KernelTranslation).
        $controller = new KernelTranslation(new GroupApiController($groupService, $authGuard));

        return [$controller, $groupRepository, $userRepository, $authService];
    }

    private function createUser(MysqlUserRepository $userRepository, string $email, UserRole $role): User
    {
        return $userRepository->save(new User(
            id: 0,
            email: $email,
            passwordHash: password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]),
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
        $group = $groupRepository->save(new \App\Group\Entity\Group(0, 'Groupe Test', null, null, 'contact@example.test'));

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
        $group = $groupRepository->save(new \App\Group\Entity\Group(0, 'Groupe Test', null, null, 'contact@example.test'));

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
        $group = $groupRepository->save(new \App\Group\Entity\Group(0, 'Groupe Test', null, null, 'contact@example.test'));

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
        $group = $groupRepository->save(new \App\Group\Entity\Group(0, 'Groupe Test', null, null, 'contact@example.test'));

        $this->expectException(AccessDeniedException::class);

        $controller->destroy(new Request('DELETE', "/api/admin/groups/{$group->id()}", [], [], []), (string) $group->id());
    }

    #[Test]

    public function testAddMemberWithKnownEmailReturns200(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');
        $group = $groupRepository->save(new \App\Group\Entity\Group(0, 'Groupe Test', null, null, 'contact@example.test'));
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
        $group = $groupRepository->save(new \App\Group\Entity\Group(0, 'Groupe Test', null, null, 'contact@example.test'));

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
        $group = $groupRepository->save(new \App\Group\Entity\Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $user = $this->createUser($userRepository, 'alice@rehearsalbox.test', UserRole::Musicien);
        $groupRepository->addMember($group->id(), $user->id());

        $response = $controller->removeMember(
            new Request('DELETE', "/api/admin/groups/{$group->id()}/members/{$user->id()}", [], [], []),
            (string) $group->id(),
            (string) $user->id(),
        );

        self::assertSame(204, $response->statusCode());
    }

    // --- Validation et erreurs prévisibles (#224) ----------------------------------------------------

    /** @return array{0: KernelTranslation, 1: MysqlGroupRepository, 2: MysqlUserRepository} contrôleur (administrateur connecté), dépôts */
    private function asAdmin(): array
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');

        return [$controller, $groupRepository, $userRepository];
    }

    private function body(array $overrides = []): array
    {
        return array_replace(['name' => 'Nebula Sprawl', 'genre' => 'prog', 'colorHex' => '#112233', 'contactEmail' => 'contact@example.test'], $overrides);
    }

    #[Test]
    public function testAnInvalidColorOrNameIsRefusedWithAReadableReasonPerField(): void
    {
        [$controller, $groupRepository] = $this->asAdmin();

        $response = $controller->store(new Request('POST', '/x', [], $this->body(['name' => '', 'colorHex' => 'red;background:url(x)']), []));

        self::assertSame(422, $response->statusCode());
        $json = json_decode($response->body(), true);
        self::assertArrayHasKey('name', $json['fields']);
        self::assertArrayHasKey('colorHex', $json['fields']);
        self::assertStringContainsString('nom du groupe', $json['error'], 'le message affiché à l\'administrateur dit pourquoi');
        self::assertCount(0, $groupRepository->findAll());
    }

    #[Test]
    public function testValuesThatAreNotTextAreRefusedInsteadOfBecomingTheWordArray(): void
    {
        [$controller, $groupRepository] = $this->asAdmin();

        foreach ([['genre' => ['rock']], ['colorHex' => 42], ['genre' => true]] as $override) {
            $response = $controller->store(new Request('POST', '/x', [], $this->body($override), []));

            self::assertSame(422, $response->statusCode(), json_encode($override));
        }
        self::assertCount(0, $groupRepository->findAll());
    }

    #[Test]
    public function testASecondGroupWithTheSameNameIsAFieldErrorNotAServerError(): void
    {
        [$controller, $groupRepository] = $this->asAdmin();
        $controller->store(new Request('POST', '/x', [], $this->body(), []));

        $clash = $controller->store(new Request('POST', '/x', [], $this->body(['name' => 'NEBULA  sprawl']), []));

        self::assertSame(422, $clash->statusCode());
        self::assertArrayHasKey('name', json_decode($clash->body(), true)['fields']);
        self::assertCount(1, $groupRepository->findAll());
    }

    #[Test]
    public function testDeletingAGroupThatMadeSlotRequestsWorks(): void
    {
        [$controller, $groupRepository, $userRepository] = $this->asAdmin();
        $holder = $groupRepository->save(new \App\Group\Entity\Group(0, 'Titulaire', null, null, 'titulaire@example.test'));
        $requester = $groupRepository->save(new \App\Group\Entity\Group(0, 'Demandeur', null, null, 'demandeur@example.test'));
        $user = $this->createUser($userRepository, 'bob@rehearsalbox.test', UserRole::Musicien);
        $slot = (new \App\Planning\Repository\MysqlRecurringSlotRepository($this->pdo))->save(new \App\Planning\Entity\RecurringSlot(0, $holder->id(), \App\Planning\Entity\Weekday::Tuesday, '18:00:00', '20:00:00', true));
        (new \App\Planning\Repository\MysqlSlotExceptionRepository($this->pdo))->createRequest($slot->id(), new \DateTimeImmutable('+7 days'), $requester->id(), $user->id(), null);

        $response = $controller->destroy(new Request('DELETE', '/x', [], [], []), (string) $requester->id());

        self::assertSame(204, $response->statusCode());
        self::assertNull($groupRepository->findById($requester->id()));
    }

    #[Test]
    public function testAddingTheSameMemberTwiceAnswersOkTwice(): void
    {
        [$controller, $groupRepository, $userRepository] = $this->asAdmin();
        $group = $groupRepository->save(new \App\Group\Entity\Group(0, 'Groupe', null, null, 'g@example.test'));
        $this->createUser($userRepository, 'bob@rehearsalbox.test', UserRole::Musicien);

        foreach ([1, 2] as $_) {
            $response = $controller->addMember(new Request('POST', '/x', [], ['email' => 'bob@rehearsalbox.test'], []), (string) $group->id());
            self::assertSame(200, $response->statusCode());
        }
    }

    #[Test]
    public function testMalformedIdentifiersAreRefusedAsForbidden(): void
    {
        [$controller] = $this->asAdmin();

        foreach ([['update', '5abc'], ['destroy', '0'], ['addMember', '-1']] as [$action, $id]) {
            try {
                $controller->{$action}(new Request('POST', '/x', [], $this->body(['email' => 'x@example.test']), []), $id);
                self::fail('identifiant refusé attendu : ' . $id);
            } catch (AccessDeniedException) {
                self::addToAssertionCount(1);
            }
        }
        try {
            $controller->removeMember(new Request('DELETE', '/x', [], [], []), '1', '2abc');
            self::fail('identifiant d\'utilisateur refusé attendu');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }
    }
}
