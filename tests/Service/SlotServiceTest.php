<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\Weekday;
use App\Entity\Group;
use App\Entity\RecurringSlot;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Repository\MysqlSlotExceptionRepository;
use App\Repository\MysqlUserRepository;
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
        $exceptionRepository = new MysqlSlotExceptionRepository($this->pdo);
        $service = new SlotService($slotRepository, $groupRepository, $exceptionRepository);

        return [$service, $groupRepository, $slotRepository, $exceptionRepository];
    }

    public function testCreateAddsSlotToGroup(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));

        $slot = $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        self::assertSame($group->id(), $slot->groupId());
        self::assertTrue($slot->isActive());
    }

    public function testCreateWithEndBeforeStartThrowsInvalidArgument(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));

        $this->expectException(\InvalidArgumentException::class);

        $service->create($group->id(), Weekday::Tuesday, '20:00:00', '18:00:00');
    }

    public function testCreateWithEndTimeAtMaxCeilingSucceeds(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));

        $slot = $service->create($group->id(), Weekday::Tuesday, '22:00:00', '23:30:00');

        self::assertSame('23:30:00', $slot->endTime());
    }

    public function testCreateWithEndTimeAfterMaxCeilingThrowsInvalidArgument(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));

        $this->expectException(\InvalidArgumentException::class);

        $service->create($group->id(), Weekday::Tuesday, '22:00:00', '23:31:00');
    }

    public function testCreateWithEndTimeAtMidnightThrowsInvalidArgument(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));

        $this->expectException(\InvalidArgumentException::class);

        $service->create($group->id(), Weekday::Tuesday, '22:00:00', '00:00:00');
    }

    public function testCreateOverlappingSlotOnSameGroupAndDayThrows(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $this->expectException(OverlappingSlotException::class);

        $service->create($group->id(), Weekday::Tuesday, '19:00:00', '21:00:00');
    }

    public function testCreateNonOverlappingSlotOnSameDaySucceeds(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $slot = $service->create($group->id(), Weekday::Tuesday, '20:00:00', '22:00:00');

        self::assertSame('20:00:00', $slot->startTime());
    }

    public function testUpdateChangesSlotTimes(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $slot = $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $updated = $service->update($slot->id(), '19:00:00', '21:00:00');

        self::assertSame('19:00:00', $updated->startTime());
        self::assertSame('21:00:00', $updated->endTime());
    }

    public function testUpdateWithEndBeforeStartThrowsInvalidArgument(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $slot = $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $this->expectException(\InvalidArgumentException::class);

        $service->update($slot->id(), '20:00:00', '19:00:00');
    }

    public function testUpdateWithEndTimeAfterMaxCeilingThrowsInvalidArgument(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $slot = $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $this->expectException(\InvalidArgumentException::class);

        $service->update($slot->id(), '22:00:00', '23:45:00');
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
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $slot = $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $service->delete($slot->id());

        $found = $slotRepository->findById($slot->id());
        self::assertFalse($found->isActive());
    }

    public function testFindByGroupReturnsOnlySlotsOfThatGroup(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $groupA = $groupRepository->save(new Group(0, 'Groupe A', null, null, 'contact@example.test'));
        $groupB = $groupRepository->save(new Group(0, 'Groupe B', null, null, 'contact@example.test'));
        $service->create($groupA->id(), Weekday::Tuesday, '18:00:00', '20:00:00');
        $service->create($groupB->id(), Weekday::Wednesday, '19:00:00', '21:00:00');

        $found = $service->findByGroup($groupA->id());

        self::assertCount(1, $found);
    }

    public function testFindPlanningSlotsReturnsSlotWithGroupName(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $planning = $service->findFixedPlanningSlots();

        self::assertCount(1, $planning);
        self::assertSame('Groupe Test', $planning[0]->groupName());
        self::assertSame(Weekday::Tuesday, $planning[0]->slot()->weekday());
        self::assertSame($group->id(), $planning[0]->groupId());
    }

    public function testFindPlanningSlotsExcludesInactiveSlots(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $slot = $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');
        $service->delete($slot->id());

        $planning = $service->findFixedPlanningSlots();

        self::assertCount(0, $planning);
    }

    /** @return array{0: int} [requestingUserId] */
    private function acceptExceptionForCurrentWeek(
        MysqlSlotExceptionRepository $exceptionRepository,
        MysqlUserRepository $userRepository,
        int $holderSlotId,
        int $requestingGroupId,
        \DateTimeImmutable $occurrenceDate,
    ): void {
        $requestingUser = $userRepository->save(new User(
            id: 0,
            email: uniqid('user-', true) . '@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: 'Demandeur',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));

        $exception = $exceptionRepository->createRequest($holderSlotId, $occurrenceDate, $requestingGroupId, $requestingUser->id(), null);
        $exceptionRepository->respond($exception->id(), true, $requestingUser->id());
    }

    public function testFindOccasionalPlanningSlotsIncludesAcceptedExceptionForCurrentWeek(): void
    {
        [$service, $groupRepository, , $exceptionRepository] = $this->makeService();
        $userRepository = new MysqlUserRepository($this->pdo);

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe Titulaire', null, null, 'contact@example.test'));
        $holderSlot = $service->create($holderGroup->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe Demandeur', null, null, 'contact@example.test'));
        $monday = (new \DateTimeImmutable('today'))->modify('monday this week');
        $this->acceptExceptionForCurrentWeek($exceptionRepository, $userRepository, $holderSlot->id(), $requestingGroup->id(), $monday);

        $occasional = $service->findOccasionalPlanningSlots();

        self::assertCount(1, $occasional);
        self::assertSame('Groupe Demandeur', $occasional[0]->groupName());
        self::assertSame($requestingGroup->id(), $occasional[0]->groupId());
        self::assertSame(Weekday::Tuesday, $occasional[0]->slot()->weekday());
        self::assertFalse($occasional[0]->isRecurring());
        self::assertSame($monday->format('Y-m-d'), $occasional[0]->occurrenceDate()?->format('Y-m-d'));
    }

    public function testFindFixedPlanningSlotsMarksSlotsAsRecurring(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $planning = $service->findFixedPlanningSlots();

        self::assertTrue($planning[0]->isRecurring());
    }

    public function testFindOccasionalPlanningSlotsExcludesUnrelatedFixedSlots(): void
    {
        [$service, $groupRepository, , $exceptionRepository] = $this->makeService();
        $userRepository = new MysqlUserRepository($this->pdo);

        $thursdayGroup = $groupRepository->save(new Group(0, 'Groupe Jeudi', null, null, 'contact@example.test'));
        $service->create($thursdayGroup->id(), Weekday::Thursday, '18:00:00', '20:00:00');

        $holderGroup = $groupRepository->save(new Group(0, 'Groupe Titulaire Mardi', null, null, 'contact@example.test'));
        $holderSlot = $service->create($holderGroup->id(), Weekday::Tuesday, '10:00:00', '12:00:00');
        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe Occasionnel Mardi', null, null, 'contact@example.test'));
        $tuesday = (new \DateTimeImmutable('today'))->modify('monday this week')->modify('+1 day');
        $this->acceptExceptionForCurrentWeek($exceptionRepository, $userRepository, $holderSlot->id(), $requestingGroup->id(), $tuesday);

        $occasional = $service->findOccasionalPlanningSlots();

        self::assertCount(1, $occasional);
        self::assertSame('Groupe Occasionnel Mardi', $occasional[0]->groupName());
    }
}
