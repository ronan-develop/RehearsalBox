<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\UserRole;
use App\Entity\Enum\Weekday;
use App\Group\Entity\Group;
use App\Entity\RecurringSlot;
use App\Entity\User;
use App\Group\Repository\MysqlGroupRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Repository\MysqlSlotExceptionRepository;
use App\Repository\MysqlUserRepository;
use App\Security\Exception\AccessDeniedException;
use App\Service\AvailabilityService;
use App\Repository\Exception\DuplicateOccurrenceException;
use App\Service\Exception\AvailabilityValidationException;
use App\Service\Exception\RequestAlreadyRespondedException;
use App\Service\Exception\RequestChangedException;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class AvailabilityServiceTest extends RepositoryTestCase
{
    private function makeService(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $slotRepository = new MysqlRecurringSlotRepository($this->pdo);
        $exceptionRepository = new MysqlSlotExceptionRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $service = new AvailabilityService($exceptionRepository, $groupRepository, $slotRepository);

        return [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository];
    }

    /** @return array{0: int, 1: int, 2: int} [holderSlotId, holderGroupId, holderUserId] */
    private function createHolder(
        MysqlGroupRepository $groupRepository,
        MysqlRecurringSlotRepository $slotRepository,
        MysqlUserRepository $userRepository,
    ): array {
        $group = $groupRepository->save(new Group(0, 'Groupe Titulaire', null, null, 'contact@example.test'));
        $slot = $slotRepository->save(
            new RecurringSlot(0, $group->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true)
        );
        $user = $userRepository->save(new User(
            id: 0,
            email: 'alice@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]),
            displayName: 'Alice',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
        $groupRepository->addMember($group->id(), $user->id());

        return [$slot->id(), $group->id(), $user->id()];
    }

    /** @return array{0: int, 1: int} [requestingGroupId, requestingUserId] */
    private function createRequester(MysqlGroupRepository $groupRepository, MysqlUserRepository $userRepository): array
    {
        $group = $groupRepository->save(new Group(0, 'Groupe Demandeur', null, null, 'contact@example.test'));
        $user = $userRepository->save(new User(
            id: 0,
            email: 'bob@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]),
            displayName: 'Bob',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
        $groupRepository->addMember($group->id(), $user->id());

        return [$group->id(), $user->id()];
    }


    /** Un mardi futur (le créneau du titulaire est le mardi) : aujourd'hui compris s'il tombe un mardi, plus $weeksAhead semaines. */
    private function tuesday(int $weeksAhead): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('today'))->modify('tuesday this week')->modify('+1 week')->modify('+' . $weeksAhead . ' weeks');
    }

    // --- Création d'une demande, avec plage partielle (#263) -----------------------------------------------------------

    /** @return array{AvailabilityService, int, int, int, int, int} [service, créneau du titulaire (mardi 18h–20h), groupe demandeur, utilisateur demandeur, groupe titulaire, utilisateur titulaire] */
    private function requestWorld(): array
    {
        [$service, $groupRepository, $slotRepository, , $userRepository] = $this->makeService();
        [$holderSlotId, $holderGroupId, $holderUserId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        return [$service, $holderSlotId, $requestingGroupId, $requestingUserId, $holderGroupId, $holderUserId];
    }

    #[Test]
    public function testARequestForTheWholeSlotIsCreatedPendingWithoutARange(): void
    {
        [$service, $slotId, $groupId, $userId] = $this->requestWorld();

        $request = $service->createRequest($slotId, $groupId, $this->tuesday(0), 'Concert', $userId);

        self::assertTrue($request->isEnAttente());
        self::assertSame($groupId, $request->requester()->groupId());
        self::assertNull($request->range());
    }

    #[Test]
    public function testARequestMayTakeAPartOfTheSlotAtTheStartOrTheEndAndTimesAreNormalised(): void
    {
        [$service, $slotId, $groupId, $userId] = $this->requestWorld();

        $start = $service->createRequest($slotId, $groupId, $this->tuesday(0), null, $userId, '18:00', '18:30');
        $end = $service->createRequest($slotId, $groupId, $this->tuesday(1), null, $userId, '19:15:00', '20:00:00');

        self::assertSame(['18:00:00', '18:30:00'], [$start->range()?->start(), $start->range()?->end()]);
        self::assertSame(['19:15:00', '20:00:00'], [$end->range()?->start(), $end->range()?->end()]);
    }

    #[Test]
    public function testARangeOutsideTheHoldersSlotOrOffTheQuarterHourOrIncompleteOrBackwardsIsRefusedPerField(): void
    {
        [$service, $slotId, $groupId, $userId] = $this->requestWorld();

        foreach ([
            'avant le créneau' => ['17:30', '18:30'],
            'après le créneau' => ['19:30', '20:30'],
            'pas sur le quart d\'heure' => ['18:10', '18:30'],
            'une seule borne' => ['18:30', null],
            'à l\'envers' => ['19:00', '18:30'],
            'durée nulle' => ['19:00', '19:00'],
            'format invalide' => ['abc', '19:00'],
        ] as $label => [$start, $end]) {
            try {
                $service->createRequest($slotId, $groupId, $this->tuesday(0), null, $userId, $start, $end);
                self::fail("plage refusée attendue : {$label}");
            } catch (AvailabilityValidationException $e) {
                self::assertNotSame([], array_intersect(['startTime', 'endTime'], array_keys($e->fields())), $label);
            }
        }
    }

    #[Test]
    public function testTheDateMustBeTheSlotsWeekdayNotInThePastAndTheReasonMustFit(): void
    {
        [$service, $slotId, $groupId, $userId] = $this->requestWorld();

        foreach ([
            'occurrenceDate' => [$this->tuesday(0)->modify('+1 day'), null],
            'occurrenceDate ' => [$this->tuesday(0)->modify('-8 weeks'), null],
            'reason' => [$this->tuesday(0), str_repeat('x', 256)],
        ] as $field => [$date, $reason]) {
            try {
                $service->createRequest($slotId, $groupId, $date, $reason, $userId);
                self::fail("refus attendu : {$field}");
            } catch (AvailabilityValidationException $e) {
                self::assertArrayHasKey(trim($field), $e->fields());
            }
        }
    }

    #[Test]
    public function testOnlyAMemberOfTheRequestingGroupMayRequestAndNeverOnTheirOwnGroupsSlotOrAnUnknownOne(): void
    {
        [$service, $slotId, $groupId, $userId, $holderGroupId, $holderUserId] = $this->requestWorld();

        foreach ([
            'pas membre du groupe demandeur' => [$slotId, $groupId, $holderUserId],
            'son propre créneau' => [$slotId, $holderGroupId, $holderUserId],
            'créneau inconnu' => [999999, $groupId, $userId],
        ] as $label => [$target, $asGroup, $asUser]) {
            try {
                $service->createRequest($target, $asGroup, $this->tuesday(0), null, $asUser);
                self::fail("accès refusé attendu : {$label}");
            } catch (AccessDeniedException $e) {
                self::assertSame('Accès refusé.', $e->getMessage(), $label);
            }
        }
    }

    #[Test]
    public function testARequestForADateAlreadyRequestedOnThatSlotIsAConflictNotAnSqlError(): void
    {
        [$service, $slotId, $groupId, $userId] = $this->requestWorld();
        $service->createRequest($slotId, $groupId, $this->tuesday(0), null, $userId);

        $this->expectException(DuplicateOccurrenceException::class);

        $service->createRequest($slotId, $groupId, $this->tuesday(0), null, $userId, '18:00', '18:30');
    }

    #[Test]


    public function testRespondByMemberOfHolderGroupAcceptedSucceeds(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$holderSlotId, , $holderUserId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $exception = $exceptionRepository->createRequest($holderSlotId, new \DateTimeImmutable('+7 days'), $requestingGroupId, $requestingUserId, null);

        $responded = $service->respond($exception->id(), true, $holderUserId, $exception->occurrenceDate());

        self::assertFalse($responded->isEnAttente());
    }

    #[Test]

    public function testRespondByMemberOfHolderGroupRefusedSucceeds(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$holderSlotId, , $holderUserId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $exception = $exceptionRepository->createRequest($holderSlotId, new \DateTimeImmutable('+7 days'), $requestingGroupId, $requestingUserId, null);

        $responded = $service->respond($exception->id(), false, $holderUserId, $exception->occurrenceDate());

        self::assertFalse($responded->isEnAttente());
    }

    /**
     * Test central de l'inversion d'IDOR corrigée par #22 : un membre du
     * groupe DEMANDEUR (A) ne doit PAS pouvoir répondre à sa propre demande
     * — c'était exactement le bug de conception de l'ancien claim() (qui
     * vérifiait le groupe revendicateur au lieu du groupe titulaire).
     */
    #[Test]
    public function testRespondByMemberOfRequestingGroupThrowsAccessDenied(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$holderSlotId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $exception = $exceptionRepository->createRequest($holderSlotId, new \DateTimeImmutable('+7 days'), $requestingGroupId, $requestingUserId, null);

        $this->expectException(AccessDeniedException::class);

        $service->respond($exception->id(), true, $requestingUserId, $exception->occurrenceDate());
    }

    #[Test]

    public function testRespondOnAlreadyRespondedThrowsRequestAlreadyResponded(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$holderSlotId, , $holderUserId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $exception = $exceptionRepository->createRequest($holderSlotId, new \DateTimeImmutable('+7 days'), $requestingGroupId, $requestingUserId, null);

        $service->respond($exception->id(), true, $holderUserId, $exception->occurrenceDate());

        $this->expectException(RequestAlreadyRespondedException::class);

        $service->respond($exception->id(), true, $holderUserId, $exception->occurrenceDate());
    }

    #[Test]

    public function testRespondOnUnknownExceptionIsRefusedLikeAForbiddenOne(): void
    {
        [$service, $groupRepository, $slotRepository, , $userRepository] = $this->makeService();
        [, , $holderUserId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);

        $this->expectException(AccessDeniedException::class);

        $service->respond(9999, true, $holderUserId, new \DateTimeImmutable("today"));
    }

    #[Test]

    public function testFindPendingForHolderGroupDelegatesToRepositoryAfterMembershipCheck(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$holderSlotId, $holderGroupId, $holderUserId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $exceptionRepository->createRequest($holderSlotId, new \DateTimeImmutable('+7 days'), $requestingGroupId, $requestingUserId, null);

        $found = $service->findPendingForHolderGroup($holderGroupId, $holderUserId);

        self::assertCount(1, $found);
    }

    #[Test]

    public function testFindPendingForHolderGroupByNonMemberThrowsAccessDenied(): void
    {
        [$service, $groupRepository, $slotRepository, , $userRepository] = $this->makeService();
        [, $holderGroupId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $this->expectException(AccessDeniedException::class);

        $service->findPendingForHolderGroup($holderGroupId, $requestingUserId);
    }

    #[Test]

    public function testFindByRequestingGroupDelegatesToRepositoryAfterMembershipCheck(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$holderSlotId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $exceptionRepository->createRequest($holderSlotId, new \DateTimeImmutable('+7 days'), $requestingGroupId, $requestingUserId, null);

        $found = $service->findByRequestingGroup($requestingGroupId, $requestingUserId);

        self::assertCount(1, $found);
    }

    #[Test]

    public function testFindArchivedForGroupDelegatesToRepositoryAfterMembershipCheck(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$holderSlotId, $holderGroupId, $holderUserId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $exception = $exceptionRepository->createRequest($holderSlotId, new \DateTimeImmutable('2026-08-04'), $requestingGroupId, $requestingUserId, null);
        $exceptionRepository->respond($exception->id(), true, $holderUserId);

        $found = $service->findArchivedForGroup($holderGroupId, $holderUserId);

        self::assertCount(1, $found);
    }

    #[Test]

    public function testFindArchivedForGroupByNonMemberThrowsAccessDenied(): void
    {
        [$service, $groupRepository, $slotRepository, , $userRepository] = $this->makeService();
        [, $holderGroupId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $this->expectException(AccessDeniedException::class);

        $service->findArchivedForGroup($holderGroupId, $requestingUserId);
    }

    #[Test]

    public function testUpdateRequestByMemberOfRequestingGroupSucceeds(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$holderSlotId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $exception = $exceptionRepository->createRequest($holderSlotId, new \DateTimeImmutable('+7 days'), $requestingGroupId, $requestingUserId, 'Raison initiale');

        $updated = $service->updateRequest($exception->id(), $this->tuesday(2), 'Raison modifiée', $requestingUserId);

        self::assertSame(($this->tuesday(2))->format('Y-m-d'), $updated->occurrenceDate()->format('Y-m-d'));
        self::assertSame('Raison modifiée', $updated->requestReason());
    }

    /**
     * IDOR : seul un membre du groupe DEMANDEUR (A) peut modifier sa propre
     * demande — ni le groupe titulaire (B), ni un tiers.
     */
    #[Test]
    public function testUpdateRequestByMemberOfHolderGroupThrowsAccessDenied(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$holderSlotId, , $holderUserId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $exception = $exceptionRepository->createRequest($holderSlotId, new \DateTimeImmutable('+7 days'), $requestingGroupId, $requestingUserId, null);

        $this->expectException(AccessDeniedException::class);

        $service->updateRequest($exception->id(), $this->tuesday(2), null, $holderUserId);
    }

    #[Test]

    public function testUpdateRequestOnAlreadyRespondedThrowsRequestAlreadyResponded(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$holderSlotId, , $holderUserId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $exception = $exceptionRepository->createRequest($holderSlotId, new \DateTimeImmutable('+7 days'), $requestingGroupId, $requestingUserId, null);
        $service->respond($exception->id(), true, $holderUserId, $exception->occurrenceDate());

        $this->expectException(RequestAlreadyRespondedException::class);

        $service->updateRequest($exception->id(), $this->tuesday(2), null, $requestingUserId);
    }

    #[Test]

    public function testUpdateRequestOnUnknownExceptionIsRefusedLikeAForbiddenOne(): void
    {
        [$service, $groupRepository, $slotRepository, , $userRepository] = $this->makeService();
        [, , $holderUserId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);

        $this->expectException(AccessDeniedException::class);

        $service->updateRequest(9999, $this->tuesday(2), null, $holderUserId);
    }

    #[Test]

    public function testCancelRequestByMemberOfRequestingGroupSucceeds(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$holderSlotId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $exception = $exceptionRepository->createRequest($holderSlotId, new \DateTimeImmutable('+7 days'), $requestingGroupId, $requestingUserId, null);

        $service->cancelRequest($exception->id(), $requestingUserId);

        self::assertNull($exceptionRepository->findById($exception->id()));
    }

    /**
     * IDOR : seul un membre du groupe DEMANDEUR (A) peut annuler sa propre
     * demande — ni le groupe titulaire (B), ni un tiers.
     */
    #[Test]
    public function testCancelRequestByMemberOfHolderGroupThrowsAccessDenied(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$holderSlotId, , $holderUserId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $exception = $exceptionRepository->createRequest($holderSlotId, new \DateTimeImmutable('+7 days'), $requestingGroupId, $requestingUserId, null);

        $this->expectException(AccessDeniedException::class);

        $service->cancelRequest($exception->id(), $holderUserId);
    }

    #[Test]

    public function testCancelRequestOnAlreadyRespondedThrowsRequestAlreadyResponded(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$holderSlotId, , $holderUserId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $exception = $exceptionRepository->createRequest($holderSlotId, new \DateTimeImmutable('+7 days'), $requestingGroupId, $requestingUserId, null);
        $service->respond($exception->id(), true, $holderUserId, $exception->occurrenceDate());

        $this->expectException(RequestAlreadyRespondedException::class);

        $service->cancelRequest($exception->id(), $requestingUserId);
    }

    #[Test]

    public function testCancelRequestOnUnknownExceptionIsRefusedLikeAForbiddenOne(): void
    {
        [$service, $groupRepository, $slotRepository, , $userRepository] = $this->makeService();
        [, , $holderUserId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);

        $this->expectException(AccessDeniedException::class);

        $service->cancelRequest(9999, $holderUserId);
    }

    // --- Intégrité d'une demande (#221) -------------------------------------------------------------

    /** @return array{0: AvailabilityService, 1: int, 2: int, 3: int, 4: \App\Entity\SlotException} service, utilisateur titulaire, utilisateur demandeur, créneau, demande */
    private function pendingRequest(?string $reason = null): array
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$holderSlotId, , $holderUserId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);
        $exception = $exceptionRepository->createRequest($holderSlotId, $this->tuesday(1), $requestingGroupId, $requestingUserId, $reason);

        return [$service, $holderUserId, $requestingUserId, $holderSlotId, $exception];
    }

    #[Test]
    public function testTheRequesterCannotChangeTheDateJustBeforeTheHolderAccepts(): void
    {
        [$service, $holderUserId, $requestingUserId, , $exception] = $this->pendingRequest();
        $seenByTheHolder = $exception->occurrenceDate();

        $service->updateRequest($exception->id(), $this->tuesday(3), null, $requestingUserId);

        try {
            $service->respond($exception->id(), true, $holderUserId, $seenByTheHolder);
            self::fail('l\'acceptation d\'une date que le titulaire n\'a pas vue doit être refusée');
        } catch (RequestChangedException $e) {
            self::assertStringContainsString('modifiée', $e->getMessage());
        }
        // La demande reste en attente, avec sa nouvelle date : le titulaire la revoit puis décide en connaissance de cause.
        $pending = $service->findPendingForHolderGroup($this->holderGroupOf($service), $holderUserId);
        self::assertCount(1, $pending);
        self::assertSame($this->tuesday(3)->format('Y-m-d'), $pending[0]->occurrenceDate()->format('Y-m-d'));
    }

    private function holderGroupOf(AvailabilityService $service): int
    {
        return (int) $this->pdo->query('SELECT group_id FROM recurring_slots LIMIT 1')->fetchColumn();
    }

    #[Test]
    public function testAnAlreadyRespondedRequestStillSaysSoRatherThanChanged(): void
    {
        [$service, $holderUserId, , , $exception] = $this->pendingRequest();
        $service->respond($exception->id(), true, $holderUserId, $exception->occurrenceDate());

        $this->expectException(RequestAlreadyRespondedException::class);
        $service->respond($exception->id(), false, $holderUserId, $exception->occurrenceDate());
    }

    #[Test]
    public function testTheNewDateMustFallOnTheWeekdayOfTheSlot(): void
    {
        [$service, , $requestingUserId, , $exception] = $this->pendingRequest();

        try {
            $service->updateRequest($exception->id(), $this->tuesday(2)->modify('+1 day'), null, $requestingUserId);
            self::fail('un mercredi ne peut pas remplacer un mardi');
        } catch (AvailabilityValidationException $e) {
            self::assertArrayHasKey('occurrenceDate', $e->fields());
        }
    }

    #[Test]
    public function testThePastCannotBeRequested(): void
    {
        [$service, , $requestingUserId, , $exception] = $this->pendingRequest();
        $pastTuesday = (new \DateTimeImmutable('today'))->modify('last tuesday')->modify('-1 week');

        $this->expectException(AvailabilityValidationException::class);
        $service->updateRequest($exception->id(), $pastTuesday, null, $requestingUserId);
    }

    #[Test]
    public function testTheReasonIsBoundedToWhatTheColumnCanHold(): void
    {
        [$service, , $requestingUserId, , $exception] = $this->pendingRequest();

        $accepted = $service->updateRequest($exception->id(), $this->tuesday(2), str_repeat('é', 255), $requestingUserId);
        self::assertSame(255, mb_strlen((string) $accepted->requestReason()));

        try {
            $service->updateRequest($exception->id(), $this->tuesday(2), str_repeat('é', 256), $requestingUserId);
            self::fail('un motif de 256 caractères doit être refusé');
        } catch (AvailabilityValidationException $e) {
            self::assertArrayHasKey('reason', $e->fields());
        }
    }

    #[Test]
    public function testMovingARequestOntoADateAlreadyRequestedIsAConflictNotAServerError(): void
    {
        [$service, , $requestingUserId, $slotId, $exception] = $this->pendingRequest();
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $requestingGroupId = $exception->requester()->groupId();
        (new MysqlSlotExceptionRepository($this->pdo))->createRequest($slotId, $this->tuesday(2), $requestingGroupId, $requestingUserId, null);

        $this->expectException(DuplicateOccurrenceException::class);
        $service->updateRequest($exception->id(), $this->tuesday(2), null, $requestingUserId);
    }
}
