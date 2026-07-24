<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\UserRole;
use App\Entity\Enum\Weekday;
use App\Entity\Group;
use App\Entity\RecurringSlot;
use App\Entity\User;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Repository\MysqlSlotExceptionRepository;
use App\Repository\MysqlUserRepository;
use App\Security\Exception\AccessDeniedException;
use App\Service\AvailabilityService;
use App\Service\Exception\RequestAlreadyRespondedException;
use App\Tests\RepositoryTestCase;

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
        $group = $groupRepository->save(new Group(0, 'Groupe Titulaire', null, null));
        $slot = $slotRepository->save(
            new RecurringSlot(0, $group->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true)
        );
        $user = $userRepository->save(new User(
            id: 0,
            email: 'alice@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
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
        $group = $groupRepository->save(new Group(0, 'Groupe Demandeur', null, null));
        $user = $userRepository->save(new User(
            id: 0,
            email: 'bob@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: 'Bob',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
        $groupRepository->addMember($group->id(), $user->id());

        return [$group->id(), $user->id()];
    }

    public function testRequestByMemberOfRequestingGroupSucceeds(): void
    {
        [$service, $groupRepository, $slotRepository, , $userRepository] = $this->makeService();
        [$holderSlotId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $created = $service->request($holderSlotId, new \DateTimeImmutable('2026-08-04'), $requestingGroupId, $requestingUserId, 'Concert samedi');

        self::assertTrue($created->isEnAttente());
        self::assertSame($requestingGroupId, $created->requestedByGroupId());
    }

    /**
     * IDOR (création) : l'appelant doit appartenir au groupe DEMANDEUR fourni
     * même si ce groupe existe bel et bien — jamais fait confiance au payload
     * client seul.
     */
    public function testRequestByUserNotMemberOfRequestingGroupThrowsAccessDenied(): void
    {
        [$service, $groupRepository, $slotRepository, , $userRepository] = $this->makeService();
        [$holderSlotId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId] = $this->createRequester($groupRepository, $userRepository);

        $chris = $userRepository->save(new User(
            id: 0,
            email: 'chris@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: 'Chris',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
        // Chris n'est volontairement PAS ajouté comme membre de $requestingGroupId.

        $this->expectException(AccessDeniedException::class);

        $service->request($holderSlotId, new \DateTimeImmutable('2026-08-04'), $requestingGroupId, $chris->id(), null);
    }

    public function testRespondByMemberOfHolderGroupAcceptedSucceeds(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$holderSlotId, , $holderUserId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $exception = $exceptionRepository->createRequest($holderSlotId, new \DateTimeImmutable('2026-08-04'), $requestingGroupId, $requestingUserId, null);

        $responded = $service->respond($exception->id(), true, $holderUserId);

        self::assertFalse($responded->isEnAttente());
    }

    public function testRespondByMemberOfHolderGroupRefusedSucceeds(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$holderSlotId, , $holderUserId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $exception = $exceptionRepository->createRequest($holderSlotId, new \DateTimeImmutable('2026-08-04'), $requestingGroupId, $requestingUserId, null);

        $responded = $service->respond($exception->id(), false, $holderUserId);

        self::assertFalse($responded->isEnAttente());
    }

    /**
     * Test central de l'inversion d'IDOR corrigée par #22 : un membre du
     * groupe DEMANDEUR (A) ne doit PAS pouvoir répondre à sa propre demande
     * — c'était exactement le bug de conception de l'ancien claim() (qui
     * vérifiait le groupe revendicateur au lieu du groupe titulaire).
     */
    public function testRespondByMemberOfRequestingGroupThrowsAccessDenied(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$holderSlotId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $exception = $exceptionRepository->createRequest($holderSlotId, new \DateTimeImmutable('2026-08-04'), $requestingGroupId, $requestingUserId, null);

        $this->expectException(AccessDeniedException::class);

        $service->respond($exception->id(), true, $requestingUserId);
    }

    public function testRespondOnAlreadyRespondedThrowsRequestAlreadyResponded(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$holderSlotId, , $holderUserId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $exception = $exceptionRepository->createRequest($holderSlotId, new \DateTimeImmutable('2026-08-04'), $requestingGroupId, $requestingUserId, null);

        $service->respond($exception->id(), true, $holderUserId);

        $this->expectException(RequestAlreadyRespondedException::class);

        $service->respond($exception->id(), true, $holderUserId);
    }

    public function testRespondOnUnknownExceptionThrowsRequestAlreadyResponded(): void
    {
        [$service, $groupRepository, $slotRepository, , $userRepository] = $this->makeService();
        [, , $holderUserId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);

        $this->expectException(RequestAlreadyRespondedException::class);

        $service->respond(9999, true, $holderUserId);
    }

    public function testFindPendingForHolderGroupDelegatesToRepositoryAfterMembershipCheck(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$holderSlotId, $holderGroupId, $holderUserId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $exceptionRepository->createRequest($holderSlotId, new \DateTimeImmutable('2026-08-04'), $requestingGroupId, $requestingUserId, null);

        $found = $service->findPendingForHolderGroup($holderGroupId, $holderUserId);

        self::assertCount(1, $found);
    }

    public function testFindPendingForHolderGroupByNonMemberThrowsAccessDenied(): void
    {
        [$service, $groupRepository, $slotRepository, , $userRepository] = $this->makeService();
        [, $holderGroupId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $this->expectException(AccessDeniedException::class);

        $service->findPendingForHolderGroup($holderGroupId, $requestingUserId);
    }

    public function testFindByRequestingGroupDelegatesToRepositoryAfterMembershipCheck(): void
    {
        [$service, $groupRepository, $slotRepository, $exceptionRepository, $userRepository] = $this->makeService();
        [$holderSlotId] = $this->createHolder($groupRepository, $slotRepository, $userRepository);
        [$requestingGroupId, $requestingUserId] = $this->createRequester($groupRepository, $userRepository);

        $exceptionRepository->createRequest($holderSlotId, new \DateTimeImmutable('2026-08-04'), $requestingGroupId, $requestingUserId, null);

        $found = $service->findByRequestingGroup($requestingGroupId, $requestingUserId);

        self::assertCount(1, $found);
    }
}
