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
        $availabilityService = new AvailabilityService($exceptionRepository, $groupRepository, $slotRepository);

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

    public function testRequestByAuthenticatedMemberReturns201(): void
    {
        [$controller, $groupRepository, $slotRepository, , $userRepository, $authService] = $this->makeController();

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null));
        $bob = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($requestingGroup->id(), $bob->id());
        $authService->attempt('bob@rehearsalbox.test', 'password');

        $request = new Request('POST', '/api/availability/request', [], [
            'recurringSlotId' => $slot->id(),
            'occurrenceDate' => '2026-08-04',
            'requestingGroupId' => $requestingGroup->id(),
            'reason' => 'Concert samedi',
        ], []);

        $response = $controller->request($request);

        self::assertSame(201, $response->statusCode());
    }

    public function testRequestByNonMemberOfRequestingGroupThrowsAccessDenied(): void
    {
        [$controller, $groupRepository, $slotRepository, , $userRepository, $authService] = $this->makeController();

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null));
        $chris = $this->createUser($userRepository, 'chris@rehearsalbox.test');
        // Chris n'est volontairement PAS membre de $requestingGroup.
        $authService->attempt('chris@rehearsalbox.test', 'password');

        $this->expectException(\App\Security\Exception\AccessDeniedException::class);

        $request = new Request('POST', '/api/availability/request', [], [
            'recurringSlotId' => $slot->id(),
            'occurrenceDate' => '2026-08-04',
            'requestingGroupId' => $requestingGroup->id(),
        ], []);
        $controller->request($request);
    }

    public function testRequestWithoutSessionThrowsAccessDenied(): void
    {
        [$controller, $groupRepository, $slotRepository] = $this->makeController();

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));

        $this->expectException(\App\Security\Exception\AccessDeniedException::class);

        $request = new Request('POST', '/api/availability/request', [], [
            'recurringSlotId' => $slot->id(),
            'occurrenceDate' => '2026-08-04',
            'requestingGroupId' => 1,
        ], []);
        $controller->request($request);
    }

    public function testRespondAcceptedByMemberOfHolderGroupReturns200(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));
        $alice = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($holderGroup->id(), $alice->id());

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null));
        $bob = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($requestingGroup->id(), $bob->id());

        $exception = $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('2026-08-04'), $requestingGroup->id(), $bob->id(), null);

        $authService->attempt('alice@rehearsalbox.test', 'password');

        $request = new Request('POST', "/api/availability/{$exception->id()}/respond", [], ['accepted' => true], []);
        $response = $controller->respond($request, (string) $exception->id());

        self::assertSame(200, $response->statusCode());
    }

    public function testRespondByMemberOfRequestingGroupThrowsAccessDenied(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null));
        $bob = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($requestingGroup->id(), $bob->id());

        $exception = $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('2026-08-04'), $requestingGroup->id(), $bob->id(), null);

        $authService->attempt('bob@rehearsalbox.test', 'password');

        $this->expectException(\App\Security\Exception\AccessDeniedException::class);

        $request = new Request('POST', "/api/availability/{$exception->id()}/respond", [], ['accepted' => true], []);
        $controller->respond($request, (string) $exception->id());
    }

    public function testRespondOnAlreadyRespondedReturns409(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));
        $alice = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($holderGroup->id(), $alice->id());

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null));
        $bob = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($requestingGroup->id(), $bob->id());

        $exception = $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('2026-08-04'), $requestingGroup->id(), $bob->id(), null);
        $exceptionRepository->respond($exception->id(), true, $alice->id());

        $authService->attempt('alice@rehearsalbox.test', 'password');

        $request = new Request('POST', "/api/availability/{$exception->id()}/respond", [], ['accepted' => true], []);
        $response = $controller->respond($request, (string) $exception->id());

        self::assertSame(409, $response->statusCode());
    }

    public function testPendingForGroupReturnsOnlyPendingExceptions(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));
        $alice = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($holderGroup->id(), $alice->id());

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null));
        $bob = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('2026-08-04'), $requestingGroup->id(), $bob->id(), null);

        $authService->attempt('alice@rehearsalbox.test', 'password');

        $request = new Request('GET', "/api/availability/pending/{$holderGroup->id()}", [], [], []);
        $response = $controller->pendingForGroup($request, (string) $holderGroup->id());

        self::assertSame(200, $response->statusCode());
    }

    public function testRequestedByGroupReturnsRequestsForThatGroup(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null));
        $bob = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($requestingGroup->id(), $bob->id());
        $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('2026-08-04'), $requestingGroup->id(), $bob->id(), null);

        $authService->attempt('bob@rehearsalbox.test', 'password');

        $request = new Request('GET', "/api/availability/requested/{$requestingGroup->id()}", [], [], []);
        $response = $controller->requestedByGroup($request, (string) $requestingGroup->id());

        self::assertSame(200, $response->statusCode());
    }
}
