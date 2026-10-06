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
use App\Tests\Support\FastPasswordHasher;
use App\Service\AuthService;
use App\Service\AvailabilityService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;
use App\Tests\Support\KernelTranslation;
use PHPUnit\Framework\Attributes\Test;

final class AvailabilityApiControllerTest extends RepositoryTestCase
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
        $availabilityService = new AvailabilityService($exceptionRepository, $groupRepository, $slotRepository);

        $controller = new KernelTranslation(new AvailabilityApiController($availabilityService, $authGuard));

        return [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService];
    }

    /** Un mardi futur au format AAAA-MM-JJ (les créneaux de ces tests tombent le mardi). */
    private function futureTuesday(int $weeks = 2): string
    {
        return (new \DateTimeImmutable('today'))->modify('tuesday this week')->modify('+' . ($weeks + 1) . ' weeks')->format('Y-m-d');
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

    #[Test]

    public function testRespondAcceptedByMemberOfHolderGroupReturns200(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null, 'contact@example.test'));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));
        $alice = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($holderGroup->id(), $alice->id());

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null, 'contact@example.test'));
        $bob = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($requestingGroup->id(), $bob->id());

        $exception = $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('+7 days'), $requestingGroup->id(), $bob->id(), null);

        $authService->attempt('alice@rehearsalbox.test', 'password');

        $request = new Request('POST', "/api/availability/{$exception->id()}/respond", [], ['accepted' => true, 'occurrenceDate' => $exception->occurrenceDate()->format('Y-m-d')], []);
        $response = $controller->respond($request, (string) $exception->id());

        self::assertSame(200, $response->statusCode());
    }

    #[Test]

    public function testRespondByMemberOfRequestingGroupThrowsAccessDenied(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null, 'contact@example.test'));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null, 'contact@example.test'));
        $bob = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($requestingGroup->id(), $bob->id());

        $exception = $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('+7 days'), $requestingGroup->id(), $bob->id(), null);

        $authService->attempt('bob@rehearsalbox.test', 'password');

        $this->expectException(\App\Security\Exception\AccessDeniedException::class);

        $request = new Request('POST', "/api/availability/{$exception->id()}/respond", [], ['accepted' => true, 'occurrenceDate' => $exception->occurrenceDate()->format('Y-m-d')], []);
        $controller->respond($request, (string) $exception->id());
    }

    #[Test]

    public function testRespondOnAlreadyRespondedReturns409(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null, 'contact@example.test'));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));
        $alice = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($holderGroup->id(), $alice->id());

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null, 'contact@example.test'));
        $bob = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($requestingGroup->id(), $bob->id());

        $exception = $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('+7 days'), $requestingGroup->id(), $bob->id(), null);
        $exceptionRepository->respond($exception->id(), true, $alice->id());

        $authService->attempt('alice@rehearsalbox.test', 'password');

        $request = new Request('POST', "/api/availability/{$exception->id()}/respond", [], ['accepted' => true, 'occurrenceDate' => $exception->occurrenceDate()->format('Y-m-d')], []);
        $response = $controller->respond($request, (string) $exception->id());

        self::assertSame(409, $response->statusCode());
    }

    #[Test]

    public function testPendingForGroupReturnsOnlyPendingExceptions(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null, 'contact@example.test'));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));
        $alice = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($holderGroup->id(), $alice->id());

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null, 'contact@example.test'));
        $bob = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('+7 days'), $requestingGroup->id(), $bob->id(), null);

        $authService->attempt('alice@rehearsalbox.test', 'password');

        $request = new Request('GET', "/api/availability/pending/{$holderGroup->id()}", [], [], []);
        $response = $controller->pendingForGroup($request, (string) $holderGroup->id());

        self::assertSame(200, $response->statusCode());
    }

    #[Test]

    public function testRequestedByGroupReturnsRequestsForThatGroup(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null, 'contact@example.test'));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null, 'contact@example.test'));
        $bob = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($requestingGroup->id(), $bob->id());
        $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('+7 days'), $requestingGroup->id(), $bob->id(), null);

        $authService->attempt('bob@rehearsalbox.test', 'password');

        $request = new Request('GET', "/api/availability/requested/{$requestingGroup->id()}", [], [], []);
        $response = $controller->requestedByGroup($request, (string) $requestingGroup->id());

        self::assertSame(200, $response->statusCode());
    }

    #[Test]

    public function testUpdateByMemberOfRequestingGroupReturns200(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null, 'contact@example.test'));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null, 'contact@example.test'));
        $bob = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($requestingGroup->id(), $bob->id());

        $exception = $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('+7 days'), $requestingGroup->id(), $bob->id(), 'Raison initiale');

        $authService->attempt('bob@rehearsalbox.test', 'password');

        $request = new Request('PATCH', "/api/availability/{$exception->id()}", [], [
            'occurrenceDate' => $this->futureTuesday(),
            'reason' => 'Raison modifiée',
        ], []);
        $response = $controller->update($request, (string) $exception->id());

        self::assertSame(200, $response->statusCode());
    }

    #[Test]

    public function testUpdateWithMalformedDateReturns422(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();
        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null, 'contact@example.test'));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));
        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null, 'contact@example.test'));
        $bob = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($requestingGroup->id(), $bob->id());
        $exception = $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('+7 days'), $requestingGroup->id(), $bob->id(), 'Raison');
        $authService->attempt('bob@rehearsalbox.test', 'password');

        foreach (['', 'pas-une-date', '2026-13-45', '2026-02-30', [$this->futureTuesday()]] as $malformed) {
            $request = new Request('PATCH', "/api/availability/{$exception->id()}", [], ['occurrenceDate' => $malformed], []);

            self::assertSame(422, $controller->update($request, (string) $exception->id())->statusCode(), 'date : ' . json_encode($malformed));
        }
    }

    #[Test]

    public function testUpdateByMemberOfHolderGroupThrowsAccessDenied(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null, 'contact@example.test'));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));
        $alice = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($holderGroup->id(), $alice->id());

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null, 'contact@example.test'));
        $bob = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($requestingGroup->id(), $bob->id());

        $exception = $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('+7 days'), $requestingGroup->id(), $bob->id(), null);

        $authService->attempt('alice@rehearsalbox.test', 'password');

        $this->expectException(\App\Security\Exception\AccessDeniedException::class);

        $request = new Request('PATCH', "/api/availability/{$exception->id()}", [], ['occurrenceDate' => $this->futureTuesday()], []);
        $controller->update($request, (string) $exception->id());
    }

    #[Test]

    public function testUpdateOnAlreadyRespondedReturns409(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null, 'contact@example.test'));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));
        $alice = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($holderGroup->id(), $alice->id());

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null, 'contact@example.test'));
        $bob = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($requestingGroup->id(), $bob->id());

        $exception = $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('+7 days'), $requestingGroup->id(), $bob->id(), null);
        $exceptionRepository->respond($exception->id(), true, $alice->id());

        $authService->attempt('bob@rehearsalbox.test', 'password');

        $request = new Request('PATCH', "/api/availability/{$exception->id()}", [], ['occurrenceDate' => $this->futureTuesday()], []);
        $response = $controller->update($request, (string) $exception->id());

        self::assertSame(409, $response->statusCode());
    }

    #[Test]

    public function testDestroyByMemberOfRequestingGroupReturns204(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null, 'contact@example.test'));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null, 'contact@example.test'));
        $bob = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($requestingGroup->id(), $bob->id());

        $exception = $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('+7 days'), $requestingGroup->id(), $bob->id(), null);

        $authService->attempt('bob@rehearsalbox.test', 'password');

        $request = new Request('DELETE', "/api/availability/{$exception->id()}", [], [], []);
        $response = $controller->destroy($request, (string) $exception->id());

        self::assertSame(204, $response->statusCode());
    }

    #[Test]

    public function testDestroyByMemberOfHolderGroupThrowsAccessDenied(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null, 'contact@example.test'));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));
        $alice = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($holderGroup->id(), $alice->id());

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null, 'contact@example.test'));
        $bob = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($requestingGroup->id(), $bob->id());

        $exception = $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('+7 days'), $requestingGroup->id(), $bob->id(), null);

        $authService->attempt('alice@rehearsalbox.test', 'password');

        $this->expectException(\App\Security\Exception\AccessDeniedException::class);

        $request = new Request('DELETE', "/api/availability/{$exception->id()}", [], [], []);
        $controller->destroy($request, (string) $exception->id());
    }

    #[Test]

    public function testDestroyOnAlreadyRespondedReturns409(): void
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null, 'contact@example.test'));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));
        $alice = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($holderGroup->id(), $alice->id());

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null, 'contact@example.test'));
        $bob = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($requestingGroup->id(), $bob->id());

        $exception = $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable('+7 days'), $requestingGroup->id(), $bob->id(), null);
        $exceptionRepository->respond($exception->id(), true, $alice->id());

        $authService->attempt('bob@rehearsalbox.test', 'password');

        $request = new Request('DELETE', "/api/availability/{$exception->id()}", [], [], []);
        $response = $controller->destroy($request, (string) $exception->id());

        self::assertSame(409, $response->statusCode());
    }

    // --- Réponse stricte et date épinglée (#221) -------------------------------------------------------

    /** @return array{0: KernelTranslation, 1: \App\Entity\SlotException, 2: User, 3: User, 4: MysqlSlotExceptionRepository, 5: AuthService} contrôleur, demande, titulaire (connecté), demandeur, dépôt, authentification */
    private function pending(): array
    {
        [$controller, $groupRepository, $slotRepository, $exceptionRepository, $userRepository, $authService] = $this->makeController();
        $holderGroup = $groupRepository->save(new Group(0, 'Groupe A', null, null, 'contact@example.test'));
        $slot = $slotRepository->save(new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));
        $alice = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($holderGroup->id(), $alice->id());
        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe B', null, null, 'contact@example.test'));
        $bob = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($requestingGroup->id(), $bob->id());
        $exception = $exceptionRepository->createRequest($slot->id(), new \DateTimeImmutable($this->futureTuesday(1)), $requestingGroup->id(), $bob->id(), null);
        $authService->attempt('alice@rehearsalbox.test', 'password');

        return [$controller, $exception, $alice, $bob, $exceptionRepository, $authService];
    }

    #[Test]
    public function testAcceptedIsReadStrictlyAndTheStringFalseDeclinesInsteadOfAccepting(): void
    {
        [$controller, $exception] = $this->pending();

        $response = $controller->respond(new Request('POST', '/x', [], ['accepted' => 'false', 'occurrenceDate' => $exception->occurrenceDate()->format('Y-m-d')], []), (string) $exception->id());

        self::assertSame(200, $response->statusCode());
        self::assertSame('refusee', json_decode($response->body(), true)['status'], '« false » refuse, il n\'accepte jamais');
    }

    #[Test]
    public function testAnUnreadableAnswerOrAMissingDateIsRefusedWith422AndChangesNothing(): void
    {
        [$controller, $exception, , , $exceptionRepository] = $this->pending();
        $date = $exception->occurrenceDate()->format('Y-m-d');

        foreach ([
            ['accepted' => 'peut-être', 'occurrenceDate' => $date],
            ['accepted' => null, 'occurrenceDate' => $date],
            ['occurrenceDate' => $date],
            ['accepted' => true],
            ['accepted' => true, 'occurrenceDate' => '2026-02-30'],
            ['accepted' => true, 'occurrenceDate' => ['2026-10-13']],
        ] as $body) {
            $response = $controller->respond(new Request('POST', '/x', [], $body, []), (string) $exception->id());

            self::assertSame(422, $response->statusCode(), json_encode($body));
        }
        self::assertTrue($exceptionRepository->findById($exception->id())->isEnAttente());
    }

    #[Test]
    public function testAnAcceptanceForADateTheHolderDidNotSeeIsAConflictAndTheRequestStaysPending(): void
    {
        [$controller, $exception, , $bob, $exceptionRepository] = $this->pending();
        $seen = $exception->occurrenceDate();
        $exceptionRepository->update($exception->id(), $seen->modify('+14 days'), null);

        $response = $controller->respond(new Request('POST', '/x', [], ['accepted' => true, 'occurrenceDate' => $seen->format('Y-m-d')], []), (string) $exception->id());

        self::assertSame(409, $response->statusCode());
        self::assertTrue($exceptionRepository->findById($exception->id())->isEnAttente());
    }

    #[Test]
    public function testAMalformedIdentifierIsRefusedAsForbiddenInsteadOfBeingTruncated(): void
    {
        [$controller, $exception] = $this->pending();

        foreach (['5abc', '0', '-1', ''] as $id) {
            try {
                $controller->respond(new Request('POST', '/x', [], ['accepted' => true, 'occurrenceDate' => $exception->occurrenceDate()->format('Y-m-d')], []), $id);
                self::fail('identifiant refusé attendu : ' . $id);
            } catch (\App\Security\Exception\AccessDeniedException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testUpdatingToAnInvalidDateOrReasonIsAFieldError(): void
    {
        [$controller, $exception, , , , $authService] = $this->pending();
        $authService->attempt('bob@rehearsalbox.test', 'password');

        $wrongDay = $controller->update(new Request('PATCH', '/x', [], ['occurrenceDate' => (new \DateTimeImmutable($this->futureTuesday(2)))->modify('+1 day')->format('Y-m-d')], []), (string) $exception->id());
        $longReason = $controller->update(new Request('PATCH', '/x', [], ['occurrenceDate' => $this->futureTuesday(2), 'reason' => str_repeat('a', 256)], []), (string) $exception->id());
        $arrayReason = $controller->update(new Request('PATCH', '/x', [], ['occurrenceDate' => $this->futureTuesday(2), 'reason' => ['x']], []), (string) $exception->id());

        self::assertSame(422, $wrongDay->statusCode());
        self::assertArrayHasKey('occurrenceDate', json_decode($wrongDay->body(), true)['fields']);
        self::assertSame(422, $longReason->statusCode());
        self::assertArrayHasKey('reason', json_decode($longReason->body(), true)['fields']);
        self::assertSame(422, $arrayReason->statusCode());
    }

    /** @return array{object, array<string, int>, AuthService} le contrôleur, les identifiants utiles, l'authentification */
    private function requestWorld(): array
    {
        [$controller, $groupRepository, $slotRepository, , $userRepository, $authService] = $this->makeController();
        $holder = $groupRepository->save(new Group(0, 'The Office', null, null, 'office@example.test'));
        $requester = $groupRepository->save(new Group(0, 'Alpha', null, null, 'alpha@example.test'));
        $slot = $slotRepository->save(new RecurringSlot(0, $holder->id(), Weekday::Tuesday, '18:30:00', '22:45:00', true));
        $groupRepository->addMember($requester->id(), $this->createUser($userRepository, 'alice@rehearsalbox.test')->id());
        $groupRepository->addMember($holder->id(), $this->createUser($userRepository, 'bob@rehearsalbox.test')->id());

        return [$controller, ['slot' => $slot->id(), 'requester' => $requester->id(), 'holder' => $holder->id()], $authService];
    }

    #[Test]
    public function testAMemberRequestsAPartOfAnotherGroupsSlotAndGetsItBackWithItsRange(): void
    {
        [$controller, $ids, $authService] = $this->requestWorld();
        $authService->attempt('alice@rehearsalbox.test', 'password');

        $response = $controller->store(new Request('POST', '/api/availability', [], [
            'recurringSlotId' => $ids['slot'], 'groupId' => $ids['requester'], 'occurrenceDate' => $this->futureTuesday(),
            'startTime' => '18:30', 'endTime' => '19:00', 'reason' => 'On déborde un peu',
        ], []));

        self::assertSame(201, $response->statusCode());
        $exception = json_decode($response->body(), true)['exception'];
        self::assertSame(['en_attente', '18:30', '19:00', $ids['requester']], [$exception['status'], $exception['startTime'], $exception['endTime'], $exception['requestedByGroupId']]);
    }

    #[Test]
    public function testARequestWithoutARangeCoversTheWholeSlotAndInvalidInputIsRefusedPerField(): void
    {
        [$controller, $ids, $authService] = $this->requestWorld();
        $authService->attempt('alice@rehearsalbox.test', 'password');
        $body = ['recurringSlotId' => $ids['slot'], 'groupId' => $ids['requester'], 'occurrenceDate' => $this->futureTuesday()];

        $whole = json_decode($controller->store(new Request('POST', '/x', [], $body, []))->body(), true)['exception'];
        self::assertNull($whole['startTime']);
        self::assertNull($whole['endTime']);

        $outside = $controller->store(new Request('POST', '/x', [], ['occurrenceDate' => $this->futureTuesday(1), 'startTime' => '17:00', 'endTime' => '19:00'] + $body, []));
        self::assertSame(422, $outside->statusCode());
        self::assertArrayHasKey('startTime', json_decode($outside->body(), true)['fields']);

        $badDate = $controller->store(new Request('POST', '/x', [], ['occurrenceDate' => '07/10/2026'] + $body, []));
        self::assertSame(422, $badDate->statusCode());
        self::assertArrayHasKey('occurrenceDate', json_decode($badDate->body(), true)['fields']);
    }

    #[Test]
    public function testTheSameDateCannotBeRequestedTwiceOnASlot(): void
    {
        [$controller, $ids, $authService] = $this->requestWorld();
        $authService->attempt('alice@rehearsalbox.test', 'password');
        $body = ['recurringSlotId' => $ids['slot'], 'groupId' => $ids['requester'], 'occurrenceDate' => $this->futureTuesday()];
        $controller->store(new Request('POST', '/x', [], $body, []));

        self::assertSame(409, $controller->store(new Request('POST', '/x', [], $body, []))->statusCode());
    }

    #[Test]
    public function testItRequiresALoginAndRefusesANonMemberTheOwnSlotAnUnknownSlotAndMalformedIds(): void
    {
        [$controller, $ids, $authService] = $this->requestWorld();
        $body = ['recurringSlotId' => $ids['slot'], 'groupId' => $ids['requester'], 'occurrenceDate' => $this->futureTuesday()];

        try {
            $controller->store(new Request('POST', '/x', [], $body, []));
            self::fail('connexion exigée');
        } catch (\App\Security\Exception\UnauthenticatedException) {
            $this->addToAssertionCount(1);
        }

        $authService->attempt('bob@rehearsalbox.test', 'password'); // membre du groupe titulaire, pas du demandeur
        foreach ([
            'pas membre du groupe demandeur' => $body,
            'son propre créneau' => ['groupId' => $ids['holder']] + $body,
            'créneau inconnu' => ['recurringSlotId' => 999999] + $body,
            'identifiant mal formé' => ['recurringSlotId' => 'abc'] + $body,
        ] as $label => $payload) {
            try {
                $controller->store(new Request('POST', '/x', [], $payload, []));
                self::fail("accès refusé attendu : {$label}");
            } catch (\App\Security\Exception\AccessDeniedException $e) {
                self::assertSame('Accès refusé.', $e->getMessage(), $label);
            }
        }
    }
}

