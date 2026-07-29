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
use PHPUnit\Framework\Attributes\Test;

final class MysqlSlotExceptionRepositoryTest extends RepositoryTestCase
{
    #[Test]
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
        self::assertInstanceOf(\DateTimeImmutable::class, $found->createdAt());
    }

    #[Test]

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        self::assertNull($repository->findById(9999));
    }

    #[Test]

    public function testCreateRequestTwiceForSameOccurrenceViolatesUniqueConstraint(): void
    {
        [$holderSlotId, , , $requestingGroupId, $requestingUserId] = $this->createHolderAndRequester();
        $repository = new MysqlSlotExceptionRepository($this->pdo);
        $date = new \DateTimeImmutable('2026-08-04');

        $repository->createRequest($holderSlotId, $date, $requestingGroupId, $requestingUserId, null);

        $this->expectException(\PDOException::class);

        $repository->createRequest($holderSlotId, $date, $requestingGroupId, $requestingUserId, null);
    }

    #[Test]

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

    #[Test]

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

    #[Test]

    public function testFindPendingForHolderGroupExcludesPendingExceptionsWithPastOccurrenceDate(): void
    {
        [$holderSlotId, $holderGroupId, , $requestingGroupId, $requestingUserId] = $this->createHolderAndRequester();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $repository->createRequest($holderSlotId, new \DateTimeImmutable('yesterday'), $requestingGroupId, $requestingUserId, null);

        $pendingForHolder = $repository->findPendingForHolderGroup($holderGroupId);

        self::assertCount(0, $pendingForHolder);
    }

    #[Test]

    public function testFindPendingForHolderGroupIncludesPendingExceptionOccurringToday(): void
    {
        [$holderSlotId, $holderGroupId, , $requestingGroupId, $requestingUserId] = $this->createHolderAndRequester();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $repository->createRequest($holderSlotId, new \DateTimeImmutable('today'), $requestingGroupId, $requestingUserId, null);

        $pendingForHolder = $repository->findPendingForHolderGroup($holderGroupId);

        self::assertCount(1, $pendingForHolder);
    }

    #[Test]

    public function testFindByRequestingGroupExcludesPendingExceptionsWithPastOccurrenceDate(): void
    {
        [$holderSlotId, , , $requestingGroupId, $requestingUserId] = $this->createHolderAndRequester();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $repository->createRequest($holderSlotId, new \DateTimeImmutable('yesterday'), $requestingGroupId, $requestingUserId, null);

        $requestedByRequester = $repository->findByRequestingGroup($requestingGroupId);

        self::assertCount(0, $requestedByRequester);
    }

    #[Test]

    public function testFindByRequestingGroupIncludesPendingExceptionOccurringToday(): void
    {
        [$holderSlotId, , , $requestingGroupId, $requestingUserId] = $this->createHolderAndRequester();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $repository->createRequest($holderSlotId, new \DateTimeImmutable('today'), $requestingGroupId, $requestingUserId, null);

        $requestedByRequester = $repository->findByRequestingGroup($requestingGroupId);

        self::assertCount(1, $requestedByRequester);
    }

    #[Test]

    public function testFindByRequestingGroupIncludesPastAcceptedExceptionsAsHistory(): void
    {
        [$holderSlotId, , , $requestingGroupId, $requestingUserId] = $this->createHolderAndRequester();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $pastAccepted = $repository->createRequest($holderSlotId, new \DateTimeImmutable('yesterday'), $requestingGroupId, $requestingUserId, null);
        $repository->respond($pastAccepted->id(), true, $requestingUserId);

        $requestedByRequester = $repository->findByRequestingGroup($requestingGroupId);

        self::assertCount(1, $requestedByRequester);
        self::assertSame($pastAccepted->id(), $requestedByRequester[0]->id());
    }

    #[Test]

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

    #[Test]

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
    #[Test]
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

    #[Test]

    public function testRespondOnUnknownExceptionReturnsFalse(): void
    {
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        self::assertFalse($repository->respond(9999, true, 1));
    }

    #[Test]

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

    #[Test]

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

    #[Test]

    public function testUpdateOnUnknownExceptionReturnsFalse(): void
    {
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        self::assertFalse($repository->update(9999, new \DateTimeImmutable('2026-08-11'), null));
    }

    #[Test]

    public function testDeleteOnPendingExceptionSucceeds(): void
    {
        [$holderSlotId, , , $requestingGroupId, $requestingUserId] = $this->createHolderAndRequester();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $exception = $repository->createRequest($holderSlotId, new \DateTimeImmutable('2026-08-04'), $requestingGroupId, $requestingUserId, null);

        $deleted = $repository->delete($exception->id());

        self::assertTrue($deleted);
        self::assertNull($repository->findById($exception->id()));
    }

    #[Test]

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

    #[Test]

    public function testDeleteOnUnknownExceptionReturnsFalse(): void
    {
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        self::assertFalse($repository->delete(9999));
    }

    #[Test]

    public function testFindAcceptedForCurrentWeekReturnsOnlyAcceptedExceptionsWithinMondayToSunday(): void
    {
        [$holderSlotId, , , $requestingGroupId, $requestingUserId] = $this->createHolderAndRequester();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $today = new \DateTimeImmutable('today');
        $monday = $today->modify('monday this week');
        $sunday = $today->modify('sunday this week');

        $withinWeek = $repository->createRequest($holderSlotId, $monday, $requestingGroupId, $requestingUserId, null);
        $repository->respond($withinWeek->id(), true, $requestingUserId);

        $beforeWeek = $repository->createRequest($holderSlotId, $monday->modify('-7 days'), $requestingGroupId, $requestingUserId, null);
        $repository->respond($beforeWeek->id(), true, $requestingUserId);

        $afterWeek = $repository->createRequest($holderSlotId, $sunday->modify('+7 days'), $requestingGroupId, $requestingUserId, null);
        $repository->respond($afterWeek->id(), true, $requestingUserId);

        $results = $repository->findAcceptedForCurrentWeek();

        self::assertCount(1, $results);
        self::assertSame($withinWeek->id(), $results[0]->id());
    }

    #[Test]

    public function testFindAcceptedForCurrentWeekExcludesPendingAndRefusedExceptions(): void
    {
        [$holderSlotId, , , $requestingGroupId, $requestingUserId] = $this->createHolderAndRequester();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $monday = (new \DateTimeImmutable('today'))->modify('monday this week');

        $pending = $repository->createRequest($holderSlotId, $monday, $requestingGroupId, $requestingUserId, null);

        $refused = $repository->createRequest($holderSlotId, $monday->modify('+1 day'), $requestingGroupId, $requestingUserId, null);
        $repository->respond($refused->id(), false, $requestingUserId);

        $results = $repository->findAcceptedForCurrentWeek();

        self::assertCount(0, $results);
    }

    /** @return array{0: int, 1: int, 2: int, 3: int, 4: int} [holderSlotId, holderGroupId, holderUserId, requestingGroupId, requestingUserId] */
    private function createHolderAndRequester(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $slotRepository = new MysqlRecurringSlotRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe Titulaire', null, null, 'contact@example.test'));
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

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe Demandeur', null, null, 'contact@example.test'));
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
