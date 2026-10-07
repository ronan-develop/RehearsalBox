<?php

declare(strict_types=1);

namespace App\Tests\Planning\Controller;

use App\Planning\Controller\SlotApiController;
use App\Account\Entity\UserRole;
use App\Planning\Entity\Weekday;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Http\Request;
use App\Group\Repository\MysqlGroupRepository;
use App\Planning\Repository\MysqlRecurringSlotRepository;
use App\Planning\Repository\MysqlSlotExceptionRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\Exception\AccessDeniedException;
use App\Tests\Support\FastPasswordHasher;
use App\Account\Service\AuthService;
use App\Planning\Service\SlotService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class SlotApiControllerTest extends RepositoryTestCase
{
    private function makeController(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $slotRepository = new MysqlRecurringSlotRepository($this->pdo);
        $exceptionRepository = new MysqlSlotExceptionRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $session = new InMemorySession();
        $authService = new AuthService($userRepository, new FastPasswordHasher(), $session, $groupRepository);
        $authGuard = new AuthGuard($authService);
        $slotService = new SlotService($slotRepository, $groupRepository, $exceptionRepository);

        $controller = new SlotApiController($slotService, $authGuard);

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

    public function testIndexReturnsAllActiveSlots(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');

        $controller->store(new Request('POST', '/api/admin/slots', [], [
            'groupId' => $group->id(), 'weekday' => 1, 'startTime' => '18:00:00', 'endTime' => '20:00:00',
        ], []));

        $response = $controller->index(new Request('GET', '/api/admin/slots', [], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertCount(1, json_decode($response->body(), true)['slots']);
    }

    #[Test]

    public function testStoreByAdminReturns201(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
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

    #[Test]

    public function testStoreByNonAdminThrowsAccessDenied(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
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

    #[Test]

    public function testStoreWithOverlappingSlotReturns422(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
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

    #[Test]
    public function testUpdateThatOverlapsAnotherGroupsSlotReturns422AndNeverCrashes(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $alpha = $groupRepository->save(new Group(0, 'Alpha', null, null, 'alpha@example.test'));
        $beta = $groupRepository->save(new Group(0, 'Beta', null, null, 'beta@example.test'));
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');
        $mine = json_decode($controller->store(new Request('POST', '/api/admin/slots', [], [
            'groupId' => $alpha->id(), 'weekday' => 2, 'startTime' => '14:00:00', 'endTime' => '17:00:00',
        ], []))->body(), true);
        $controller->store(new Request('POST', '/api/admin/slots', [], [
            'groupId' => $beta->id(), 'weekday' => 2, 'startTime' => '18:00:00', 'endTime' => '22:00:00',
        ], []));

        $response = $controller->update(new Request('PATCH', '/api/admin/slots/' . $mine['id'], [], [
            'startTime' => '14:00:00', 'endTime' => '19:00:00',
        ], []), (string) $mine['id']);

        self::assertSame(422, $response->statusCode());
        self::assertStringNotContainsString('Beta', $response->body(), 'l\'autre groupe n\'est pas nommé');
    }

    #[Test]
    public function testStoreWithAnInvalidWeekdayReturns422InsteadOfCrashing(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');

        foreach ([7, 8, -1, 'abc', '', '1.5', null, [1]] as $weekday) {
            $response = $controller->store(new Request('POST', '/api/admin/slots', [], [
                'groupId' => $group->id(), 'weekday' => $weekday, 'startTime' => '18:00:00', 'endTime' => '20:00:00',
            ], []));

            self::assertSame(422, $response->statusCode(), 'jour : ' . json_encode($weekday));
            self::assertSame('Jour de la semaine invalide.', json_decode($response->body(), true)['error']);
        }
    }

    #[Test]
    public function testAnAbsentWeekdayIsRefusedAndNeverSilentlyBecomesMonday(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');

        $response = $controller->store(new Request('POST', '/api/admin/slots', [], ['groupId' => $group->id(), 'startTime' => '18:00:00', 'endTime' => '20:00:00'], []));

        self::assertSame(422, $response->statusCode());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM recurring_slots')->fetchColumn(), 'aucun créneau créé le lundi par défaut');
    }

    #[Test]
    public function testEveryRealWeekdayIsAcceptedFromAnIntegerOrADigitString(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $this->createUser($userRepository, 'admin@rehearsalbox.test', UserRole::Admin);
        $authService->attempt('admin@rehearsalbox.test', 'password');

        foreach ([0, '6'] as $i => $weekday) {
            $response = $controller->store(new Request('POST', '/api/admin/slots', [], [
                'groupId' => $group->id(), 'weekday' => $weekday, 'startTime' => '18:00:00', 'endTime' => '20:00:00',
            ], []));

            self::assertSame(201, $response->statusCode(), 'jour : ' . json_encode($weekday));
        }
    }

    #[Test]

    public function testUpdateByAdminReturns200(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
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

    #[Test]

    public function testDestroyByAdminReturns204(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
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
