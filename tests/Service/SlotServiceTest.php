<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\Weekday;
use App\Entity\Group;
use App\Entity\RecurringSlot;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Service\AvailabilityService;
use App\Service\Exception\OverlappingSlotException;
use App\Service\SlotService;
use App\Tests\RepositoryTestCase;

final class SlotServiceTest extends RepositoryTestCase
{
    private function makeService(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $slotRepository = new MysqlRecurringSlotRepository($this->pdo);
        $service = new SlotService($slotRepository);

        return [$service, $groupRepository, $slotRepository];
    }

    public function testCreateAddsSlotToGroup(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null));

        $slot = $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        self::assertSame($group->id(), $slot->groupId());
        self::assertTrue($slot->isActive());
    }

    public function testCreateWithEndBeforeStartThrowsInvalidArgument(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null));

        $this->expectException(\InvalidArgumentException::class);

        $service->create($group->id(), Weekday::Tuesday, '20:00:00', '18:00:00');
    }

    public function testCreateOverlappingSlotOnSameGroupAndDayThrows(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null));
        $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $this->expectException(OverlappingSlotException::class);

        $service->create($group->id(), Weekday::Tuesday, '19:00:00', '21:00:00');
    }

    public function testCreateNonOverlappingSlotOnSameDaySucceeds(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null));
        $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $slot = $service->create($group->id(), Weekday::Tuesday, '20:00:00', '22:00:00');

        self::assertSame('20:00:00', $slot->startTime());
    }

    public function testUpdateChangesSlotTimes(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null));
        $slot = $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $updated = $service->update($slot->id(), '19:00:00', '21:00:00');

        self::assertSame('19:00:00', $updated->startTime());
        self::assertSame('21:00:00', $updated->endTime());
    }

    public function testUpdateOnUnknownSlotThrowsInvalidArgument(): void
    {
        [$service] = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);

        $service->update(9999, '19:00:00', '21:00:00');
    }

    public function testDeleteDeactivatesSlot(): void
    {
        [$service, $groupRepository, $slotRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null));
        $slot = $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $service->delete($slot->id());

        $found = $slotRepository->findById($slot->id());
        self::assertFalse($found->isActive());
    }

    public function testFindByGroupReturnsOnlySlotsOfThatGroup(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $groupA = $groupRepository->save(new Group(0, 'Groupe A', null, null));
        $groupB = $groupRepository->save(new Group(0, 'Groupe B', null, null));
        $service->create($groupA->id(), Weekday::Tuesday, '18:00:00', '20:00:00');
        $service->create($groupB->id(), Weekday::Wednesday, '19:00:00', '21:00:00');

        $found = $service->findByGroup($groupA->id());

        self::assertCount(1, $found);
    }
}
