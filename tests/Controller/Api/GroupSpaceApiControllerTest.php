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
use App\Tests\Support\FastPasswordHasher;
use App\Service\AuthService;
use App\Service\GroupService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;
use App\Tests\Support\KernelTranslation;
use App\Security\Exception\AccessDeniedException;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class GroupSpaceApiControllerTest extends RepositoryTestCase
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
        $controller = new KernelTranslation(new GroupSpaceApiController($groupService, $groupRepository, $authGuard));

        return [$controller, $groupRepository, $userRepository, $authService];
    }

    private function createUser(MysqlUserRepository $userRepository, string $email): User
    {
        return $userRepository->save(new User(
            id: 0,
            email: $email,
            passwordHash: password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]),
            displayName: $email,
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
    }

    #[Test]

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

    #[Test]

    public function testShowByNonMemberReturns403(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $authService->attempt('bob@rehearsalbox.test', 'password');

        $response = $controller->show(new Request('GET', "/api/groups/{$group->id()}/space", [], [], []), (string) $group->id());

        self::assertSame(403, $response->statusCode());
    }

    #[Test]

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

    #[Test]

    public function testUpdateProfileWithMalformedPayloadReturns422(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'chris@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $authService->attempt('chris@rehearsalbox.test', 'password');

        $payloads = [
            ['lineup' => ['pas-un-tableau'], 'upcomingShows' => []],
            ['lineup' => [['name' => 'Alice']], 'upcomingShows' => []],
            ['lineup' => [['name' => ['x'], 'instrument' => 'Basse']], 'upcomingShows' => []],
            ['lineup' => [], 'upcomingShows' => [['date' => '2026-09-12']]],
            ['lineup' => 'chaine', 'upcomingShows' => []],
        ];
        foreach ($payloads as $payload) {
            $request = new Request('PATCH', "/api/groups/{$group->id()}/space", [], $payload, []);

            self::assertSame(422, $controller->updateProfile($request, (string) $group->id())->statusCode(), json_encode($payload));
        }
    }

    #[Test]

    public function testUpdateProfileByNonGestionnaireIsRefused(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $member = $this->createUser($userRepository, 'dana@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $member->id());
        $authService->attempt('dana@rehearsalbox.test', 'password');

        $request = new Request('PATCH', "/api/groups/{$group->id()}/space", [], ['lineup' => [], 'upcomingShows' => []], []);
        $this->expectException(AccessDeniedException::class);
        $controller->updateProfile($request, (string) $group->id());
    }

    // --- Profil borné et public documenté (#224) ----------------------------------------------------------

    #[Test]
    public function testAnOversizedOrInvalidProfileIsRefusedWith422AndNothingIsStored(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'gaby@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $authService->attempt('gaby@rehearsalbox.test', 'password');
        $member = static fn (int $i): array => ['name' => "Membre {$i}", 'instrument' => 'Basse'];

        $tooMany = $controller->updateProfile(new Request('PATCH', '/x', [], ['lineup' => array_map($member, range(1, 21)), 'upcomingShows' => []], []), (string) $group->id());
        $badDate = $controller->updateProfile(new Request('PATCH', '/x', [], ['lineup' => [], 'upcomingShows' => [['date' => 'bientôt', 'venue' => 'Salle']]], []), (string) $group->id());

        self::assertSame(422, $tooMany->statusCode());
        self::assertArrayHasKey('lineup', json_decode($tooMany->body(), true)['fields']);
        self::assertSame(422, $badDate->statusCode());
        self::assertArrayHasKey('upcomingShows', json_decode($badDate->body(), true)['fields']);
        self::assertSame([], $groupRepository->findById($group->id())->lineup(), 'rien n\'est enregistré');
    }

    #[Test]
    public function testAnUnknownGroupIsStillA404ForTheManagerRoute(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'gaby@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $authService->attempt('gaby@rehearsalbox.test', 'password');
        $groupRepository->delete($group->id());

        $this->expectException(AccessDeniedException::class); // plus gestionnaire d'un groupe qui n'existe plus : refus uniforme
        $controller->updateProfile(new Request('PATCH', '/x', [], ['lineup' => [], 'upcomingShows' => []], []), (string) $group->id());
    }

    #[Test]
    public function testMalformedIdentifiersAreRefusedAsForbidden(): void
    {
        [$controller, , $userRepository, $authService] = $this->makeController();
        $this->createUser($userRepository, 'gaby@rehearsalbox.test');
        $authService->attempt('gaby@rehearsalbox.test', 'password');

        foreach (['5abc', '0', '-1'] as $id) {
            foreach (['show', 'updateProfile'] as $action) {
                try {
                    $controller->{$action}(new Request('GET', '/x', [], ['lineup' => [], 'upcomingShows' => []], []), $id);
                    self::fail("{$action} : identifiant refusé attendu : {$id}");
                } catch (AccessDeniedException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }
}
