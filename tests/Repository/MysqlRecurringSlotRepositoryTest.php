<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Enum\Weekday;
use App\Entity\Group;
use App\Entity\RecurringSlot;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Tests\RepositoryTestCase;

final class MysqlRecurringSlotRepositoryTest extends RepositoryTestCase
{
    public function testSaveThenFindByIdReturnsSameSlot(): void
    {
        $groupId = $this->createGroup();
        $repository = new MysqlRecurringSlotRepository($this->pdo);

        $inserted = $repository->save(
            new RecurringSlot(0, $groupId, Weekday::Tuesday, '18:00:00', '20:00:00', true)
        );

        $found = $repository->findById($inserted->id());

        self::assertNotNull($found);
        self::assertSame($groupId, $found->groupId());
        self::assertSame(Weekday::Tuesday, $found->weekday());
        self::assertSame('18:00:00', $found->startTime());
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $repository = new MysqlRecurringSlotRepository($this->pdo);

        self::assertNull($repository->findById(9999));
    }

    public function testFindAllActiveExcludesInactiveSlots(): void
    {
        $groupId = $this->createGroup();
        $repository = new MysqlRecurringSlotRepository($this->pdo);

        $repository->save(new RecurringSlot(0, $groupId, Weekday::Monday, '18:00:00', '20:00:00', true));
        $repository->save(new RecurringSlot(0, $groupId, Weekday::Friday, '19:00:00', '21:00:00', false));

        $active = $repository->findAllActive();

        self::assertCount(1, $active);
        self::assertSame(Weekday::Monday, $active[0]->weekday());
    }

    public function testFindByGroupReturnsOnlySlotsOfThatGroup(): void
    {
        $groupA = $this->createGroup('Groupe A');
        $groupB = $this->createGroup('Groupe B');
        $repository = new MysqlRecurringSlotRepository($this->pdo);

        $repository->save(new RecurringSlot(0, $groupA, Weekday::Monday, '18:00:00', '20:00:00', true));
        $repository->save(new RecurringSlot(0, $groupB, Weekday::Tuesday, '19:00:00', '21:00:00', true));

        $slots = $repository->findByGroup($groupA);

        self::assertCount(1, $slots);
        self::assertSame($groupA, $slots[0]->groupId());
    }

    public function testInvalidTimeRangeIsRejectedByDatabaseCheckConstraint(): void
    {
        $groupId = $this->createGroup();
        $repository = new MysqlRecurringSlotRepository($this->pdo);

        $this->expectException(\PDOException::class);

        $repository->save(new RecurringSlot(0, $groupId, Weekday::Monday, '20:00:00', '18:00:00', true));
    }

    private function createGroup(string $name = 'Groupe Test'): int
    {
        $repository = new MysqlGroupRepository($this->pdo);

        return $repository->save(new Group(0, $name, null, null))->id();
    }
}
