<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Enum\UserRole;
use App\Entity\Enum\Weekday;
use App\Entity\Group;
use App\Entity\RecurringSlot;
use App\Entity\User;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Repository\MysqlSlotExceptionRepository;
use App\Repository\MysqlUserRepository;
use App\Tests\RepositoryTestCase;

final class MysqlSlotExceptionRepositoryTest extends RepositoryTestCase
{
    public function testCreateRequestThenFindByIdReturnsEnAttenteStatus(): void
    {
        [$holderSlotId, , , $requestingGroupId, $requestingUserId] = $this->createHolderAndRequester();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $created = $repository->createRequest(
            $holderSlotId,
            new \DateTimeImmutable('2026-08-04'),
            $requestingGroupId,
            $requestingUserId,
            'Concert samedi',
        );

        $found = $repository->findById($created->id());

        self::assertNotNull($found);
        self::assertTrue($found->isEnAttente());
        self::assertSame('Concert samedi', $found->requestReason());
        self::assertSame($requestingGroupId, $found->requestedByGroupId());
        self::assertSame($requestingUserId, $found->requestedByUserId());
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        self::assertNull($repository->findById(9999));
    }

    public function testCreateRequestTwiceForSameOccurrenceViolatesUniqueConstraint(): void
    {
        [$holderSlotId, , , $requestingGroupId, $requestingUserId] = $this->createHolderAndRequester();
        $repository = new MysqlSlotExceptionRepository($this->pdo);
        $date = new \DateTimeImmutable('2026-08-04');

        $repository->createRequest($holderSlotId, $date, $requestingGroupId, $requestingUserId, null);

        $this->expectException(\PDOException::class);

        $repository->createRequest($holderSlotId, $date, $requestingGroupId, $requestingUserId, null);
    }

    public function testFindPendingForHolderGroupReturnsOnlyPendingForThatGroupsSlots(): void
    {
        [$holderSlotId, $holderGroupId, , $requestingGroupId, $requestingUserId] = $this->createHolderAndRequester();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $repository->createRequest($holderSlotId, new \DateTimeImmutable('2026-08-04'), $requestingGroupId, $requestingUserId, null);

        $pendingForHolder = $repository->findPendingForHolderGroup($holderGroupId);
        $pendingForRequester = $repository->findPendingForHolderGroup($requestingGroupId);

        self::assertCount(1, $pendingForHolder);
        self::assertCount(0, $pendingForRequester);
    }

    public function testFindByRequestingGroupReturnsRequestsMadeByThatGroup(): void
    {
        [$holderSlotId, $holderGroupId, , $requestingGroupId, $requestingUserId] = $this->createHolderAndRequester();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $repository->createRequest($holderSlotId, new \DateTimeImmutable('2026-08-04'), $requestingGroupId, $requestingUserId, null);

        $requestedByRequester = $repository->findByRequestingGroup($requestingGroupId);
        $requestedByHolder = $repository->findByRequestingGroup($holderGroupId);

        self::assertCount(1, $requestedByRequester);
        self::assertCount(0, $requestedByHolder);
    }

    public function testRespondAcceptedOnPendingExceptionSucceeds(): void
    {
        [$holderSlotId, , , $requestingGroupId, $requestingUserId] = $this->createHolderAndRequester();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $exception = $repository->createRequest($holderSlotId, new \DateTimeImmutable('2026-08-04'), $requestingGroupId, $requestingUserId, null);

        $responded = $repository->respond($exception->id(), true, $requestingUserId);

        self::assertTrue($responded);

        $found = $repository->findById($exception->id());
        self::assertFalse($found->isEnAttente());
        self::assertSame($requestingUserId, $found->respondedByUserId());
    }

    public function testRespondRefusedOnPendingExceptionSucceeds(): void
    {
        [$holderSlotId, , , $requestingGroupId, $requestingUserId] = $this->createHolderAndRequester();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $exception = $repository->createRequest($holderSlotId, new \DateTimeImmutable('2026-08-04'), $requestingGroupId, $requestingUserId, null);

        $responded = $repository->respond($exception->id(), false, $requestingUserId);

        self::assertTrue($responded);
        self::assertFalse($repository->findById($exception->id())->isEnAttente());
    }

    /**
     * Le test le plus important du projet (cf. plan §0.2/§10.5, hérité de
     * l'ancien claim()) : une demande déjà répondue ne doit JAMAIS pouvoir
     * être répondue une seconde fois — respond() doit renvoyer false, jamais
     * lever, pour rester un résultat métier normal en cas de concurrence.
     */
    public function testRespondOnAlreadyRespondedExceptionReturnsFalseWithoutThrowing(): void
    {
        [$holderSlotId, , , $requestingGroupId, $requestingUserId] = $this->createHolderAndRequester();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $exception = $repository->createRequest($holderSlotId, new \DateTimeImmutable('2026-08-04'), $requestingGroupId, $requestingUserId, null);

        $firstResponse = $repository->respond($exception->id(), true, $requestingUserId);
        $secondResponse = $repository->respond($exception->id(), false, $requestingUserId);

        self::assertTrue($firstResponse);
        self::assertFalse($secondResponse);
    }

    public function testRespondOnUnknownExceptionReturnsFalse(): void
    {
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        self::assertFalse($repository->respond(9999, true, 1));
    }

    public function testUpdateOnPendingExceptionSucceeds(): void
    {
        [$holderSlotId, , , $requestingGroupId, $requestingUserId] = $this->createHolderAndRequester();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $exception = $repository->createRequest($holderSlotId, new \DateTimeImmutable('2026-08-04'), $requestingGroupId, $requestingUserId, 'Raison initiale');

        $updated = $repository->update($exception->id(), new \DateTimeImmutable('2026-08-11'), 'Raison modifiée');

        self::assertTrue($updated);

        $found = $repository->findById($exception->id());
        self::assertSame('2026-08-11', $found->occurrenceDate()->format('Y-m-d'));
        self::assertSame('Raison modifiée', $found->requestReason());
    }

    public function testUpdateOnAlreadyRespondedExceptionReturnsFalse(): void
    {
        [$holderSlotId, , , $requestingGroupId, $requestingUserId] = $this->createHolderAndRequester();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $exception = $repository->createRequest($holderSlotId, new \DateTimeImmutable('2026-08-04'), $requestingGroupId, $requestingUserId, null);
        $repository->respond($exception->id(), true, $requestingUserId);

        $updated = $repository->update($exception->id(), new \DateTimeImmutable('2026-08-11'), 'Nouvelle raison');

        self::assertFalse($updated);
        self::assertSame('2026-08-04', $repository->findById($exception->id())->occurrenceDate()->format('Y-m-d'));
    }

    public function testUpdateOnUnknownExceptionReturnsFalse(): void
    {
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        self::assertFalse($repository->update(9999, new \DateTimeImmutable('2026-08-11'), null));
    }

    public function testDeleteOnPendingExceptionSucceeds(): void
    {
        [$holderSlotId, , , $requestingGroupId, $requestingUserId] = $this->createHolderAndRequester();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $exception = $repository->createRequest($holderSlotId, new \DateTimeImmutable('2026-08-04'), $requestingGroupId, $requestingUserId, null);

        $deleted = $repository->delete($exception->id());

        self::assertTrue($deleted);
        self::assertNull($repository->findById($exception->id()));
    }

    public function testDeleteOnAlreadyRespondedExceptionReturnsFalse(): void
    {
        [$holderSlotId, , , $requestingGroupId, $requestingUserId] = $this->createHolderAndRequester();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $exception = $repository->createRequest($holderSlotId, new \DateTimeImmutable('2026-08-04'), $requestingGroupId, $requestingUserId, null);
        $repository->respond($exception->id(), true, $requestingUserId);

        $deleted = $repository->delete($exception->id());

        self::assertFalse($deleted);
        self::assertNotNull($repository->findById($exception->id()));
    }

    public function testDeleteOnUnknownExceptionReturnsFalse(): void
    {
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        self::assertFalse($repository->delete(9999));
    }

    /** @return array{0: int, 1: int, 2: int, 3: int, 4: int} [holderSlotId, holderGroupId, holderUserId, requestingGroupId, requestingUserId] */
    private function createHolderAndRequester(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $slotRepository = new MysqlRecurringSlotRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe Titulaire', null, null));
        $holderSlot = $slotRepository->save(
            new RecurringSlot(0, $holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true)
        );
        $holderUser = $userRepository->save(new User(
            id: 0,
            email: 'alice@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: 'Alice',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe Demandeur', null, null));
        $requestingUser = $userRepository->save(new User(
            id: 0,
            email: 'bob@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: 'Bob',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));

        return [$holderSlot->id(), $holderGroup->id(), $holderUser->id(), $requestingGroup->id(), $requestingUser->id()];
    }
}
