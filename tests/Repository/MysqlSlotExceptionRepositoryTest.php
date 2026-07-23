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
    public function testCreateLiberationThenFindByIdReturnsLibereeStatus(): void
    {
        [$slotId, $userId] = $this->createSlotAndUser();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $created = $repository->createLiberation(
            $slotId,
            new \DateTimeImmutable('2026-08-04'),
            $userId,
            'Tournée annulée',
        );

        $found = $repository->findById($created->id());

        self::assertNotNull($found);
        self::assertTrue($found->isLiberee());
        self::assertSame('Tournée annulée', $found->releasedReason());
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        self::assertNull($repository->findById(9999));
    }

    public function testCreateLiberationTwiceForSameOccurrenceViolatesUniqueConstraint(): void
    {
        [$slotId, $userId] = $this->createSlotAndUser();
        $repository = new MysqlSlotExceptionRepository($this->pdo);
        $date = new \DateTimeImmutable('2026-08-04');

        $repository->createLiberation($slotId, $date, $userId, null);

        $this->expectException(\PDOException::class);

        $repository->createLiberation($slotId, $date, $userId, null);
    }

    public function testFindLiberatedBetweenReturnsOnlyOccurrencesInRange(): void
    {
        [$slotId, $userId] = $this->createSlotAndUser();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $repository->createLiberation($slotId, new \DateTimeImmutable('2026-08-04'), $userId, null);
        $repository->createLiberation($slotId, new \DateTimeImmutable('2026-09-15'), $userId, null);

        $inRange = $repository->findLiberatedBetween(
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-31'),
        );

        self::assertCount(1, $inRange);
    }

    public function testClaimOnLiberatedExceptionSucceeds(): void
    {
        [$slotId, $userId, $groupId] = $this->createSlotAndUser();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $exception = $repository->createLiberation($slotId, new \DateTimeImmutable('2026-08-04'), $userId, null);

        $claimed = $repository->claim($exception->id(), $groupId, $userId);

        self::assertTrue($claimed);

        $found = $repository->findById($exception->id());
        self::assertFalse($found->isLiberee());
        self::assertSame($groupId, $found->claimedByGroupId());
    }

    /**
     * Le test le plus important du projet (cf. plan §0.2/§10.5) : une
     * exception déjà revendiquée ne doit JAMAIS pouvoir être revendiquée une
     * seconde fois — claim() doit renvoyer false, jamais lever.
     */
    public function testClaimOnAlreadyClaimedExceptionReturnsFalseWithoutThrowing(): void
    {
        [$slotId, $userId, $groupId] = $this->createSlotAndUser();
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        $exception = $repository->createLiberation($slotId, new \DateTimeImmutable('2026-08-04'), $userId, null);

        $firstClaim = $repository->claim($exception->id(), $groupId, $userId);
        $secondClaim = $repository->claim($exception->id(), $groupId, $userId);

        self::assertTrue($firstClaim);
        self::assertFalse($secondClaim);
    }

    public function testClaimOnUnknownExceptionReturnsFalse(): void
    {
        $repository = new MysqlSlotExceptionRepository($this->pdo);

        self::assertFalse($repository->claim(9999, 1, 1));
    }

    /** @return array{0: int, 1: int, 2: int} [slotId, userId, groupId] */
    private function createSlotAndUser(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $slotRepository = new MysqlRecurringSlotRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null));
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

        return [$slot->id(), $user->id(), $group->id()];
    }
}
