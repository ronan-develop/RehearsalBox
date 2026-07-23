<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\AvailabilityApiController;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\Weekday;
use App\Entity\Group;
use App\Entity\RecurringSlot;
use App\Entity\User;
use App\Http\Request;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Repository\MysqlSlotExceptionRepository;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\NativePasswordHasher;
use App\Service\AuthService;
use App\Service\AvailabilityService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;

final class AvailabilityApiControllerTest extends RepositoryTestCase
{
    private function makeController(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $slotRepository = new MysqlRecurringSlotRepository($this->pdo);
        $exceptionRepository = new MysqlSlotExceptionRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $session = new InMemorySession();
        $authService = new AuthService($userRepository, new NativePasswordHasher(), $session);
        $authGuard = new AuthGuard($authService);
        $availabilityService = new AvailabilityService($exceptionRepository, $groupRepository);

        $controller = new AvailabilityApiController($availabilityService, $authGuard);

        return [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService];
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

    public function testClaimByAuthenticatedMemberReturns200(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();

        $releasingGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null));
        $slot = $slotRepository->save(new RecurringSlot(0, $releasingGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));
        $releasingUser = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $exception = $exceptionRepository->createLiberation($slot->id(), new \DateTimeImmutable('2026-08-04'), $releasingUser->id(), null);

        $claimingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null));
        $claimingUser = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($claimingGroup->id(), $claimingUser->id());
        $authService->attempt('bob@rehearsalbox.test', 'password');

        $request = new Request('POST', "/api/availability/{$exception->id()}/claim", [], ['groupId' => $claimingGroup->id()], []);

        $response = $controller->claim($request, (string) $exception->id());

        self::assertSame(200, $response->statusCode());
    }

    public function testClaimWithoutSessionThrowsAccessDenied(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeController();

        $releasingGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null));
        $slot = $slotRepository->save(new RecurringSlot(0, $releasingGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));
        $releasingUser = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $exception = $exceptionRepository->createLiberation($slot->id(), new \DateTimeImmutable('2026-08-04'), $releasingUser->id(), null);

        $this->expectException(\App\Security\Exception\AccessDeniedException::class);

        $request = new Request('POST', "/api/availability/{$exception->id()}/claim", [], ['groupId' => $releasingGroup->id()], []);
        $controller->claim($request, (string) $exception->id());
    }

    public function testClaimOnAlreadyClaimedReturns409(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();

        $releasingGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null));
        $slot = $slotRepository->save(new RecurringSlot(0, $releasingGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));
        $releasingUser = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $exception = $exceptionRepository->createLiberation($slot->id(), new \DateTimeImmutable('2026-08-04'), $releasingUser->id(), null);

        $claimingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null));
        $claimingUser = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($claimingGroup->id(), $claimingUser->id());
        $authService->attempt('bob@rehearsalbox.test', 'password');

        $exceptionRepository->claim($exception->id(), $claimingGroup->id(), $claimingUser->id());

        $request = new Request('POST', "/api/availability/{$exception->id()}/claim", [], ['groupId' => $claimingGroup->id()], []);
        $response = $controller->claim($request, (string) $exception->id());

        self::assertSame(409, $response->statusCode());
    }

    public function testWeekViewReturnsLiberatedExceptions(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();

        $group = $groupRepository->save(new Group(0, 'Groupe A', null, null));
        $slot = $slotRepository->save(new RecurringSlot(0, $group->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));
        $user = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $exceptionRepository->createLiberation($slot->id(), new \DateTimeImmutable('2026-08-04'), $user->id(), null);
        $authService->attempt('alice@rehearsalbox.test', 'password');

        $request = new Request('GET', '/api/availability', ['from' => '2026-08-01', 'to' => '2026-08-31'], [], []);

        $response = $controller->weekView($request);

        self::assertSame(200, $response->statusCode());
    }
}
