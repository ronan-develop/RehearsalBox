<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\Weekday;
use App\Group\Entity\Group;
use App\Entity\RecurringSlot;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Group\Repository\MysqlGroupRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Repository\MysqlSlotExceptionRepository;
use App\Repository\MysqlUserRepository;
use App\Service\AvailabilityService;
use App\Service\Exception\OverlappingSlotException;
use App\Service\SlotService;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
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

    #[Test]

    public function testCreateAddsSlotToGroup(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));

        $slot = $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        self::assertSame($group->id(), $slot->groupId());
        self::assertTrue($slot->isActive());
    }

    #[Test]

    public function testCreateWithEndBeforeStartThrowsInvalidArgument(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));

        $this->expectException(\InvalidArgumentException::class);

        $service->create($group->id(), Weekday::Tuesday, '20:00:00', '18:00:00');
    }

    #[Test]

    public function testCreateWithEndTimeAtMaxCeilingSucceeds(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));

        $slot = $service->create($group->id(), Weekday::Tuesday, '22:00:00', '23:30:00');

        self::assertSame('23:30:00', $slot->endTime());
    }

    #[Test]

    public function testCreateWithEndTimeAfterMaxCeilingThrowsInvalidArgument(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));

        $this->expectException(\InvalidArgumentException::class);

        $service->create($group->id(), Weekday::Tuesday, '22:00:00', '23:31:00');
    }

    #[Test]

    public function testCreateWithEndTimeAtMidnightThrowsInvalidArgument(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));

        $this->expectException(\InvalidArgumentException::class);

        $service->create($group->id(), Weekday::Tuesday, '22:00:00', '00:00:00');
    }

    #[Test]

    public function testCreateOverlappingSlotOnSameGroupAndDayThrows(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $this->expectException(OverlappingSlotException::class);

        $service->create($group->id(), Weekday::Tuesday, '19:00:00', '21:00:00');
    }

    #[Test]

    public function testCreateNonOverlappingSlotOnSameDaySucceeds(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $slot = $service->create($group->id(), Weekday::Tuesday, '20:00:00', '22:00:00');

        self::assertSame('20:00:00', $slot->startTime());
    }

    #[Test]

    public function testTheLocalIsExclusiveASlotCannotOverlapAnotherGroupsSlot(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $alpha = $groupRepository->save(new Group(0, 'Alpha', null, null, 'alpha@example.test'));
        $beta = $groupRepository->save(new Group(0, 'The Office', null, null, 'beta@example.test'));
        $service->create($beta->id(), Weekday::Wednesday, '18:30:00', '22:45:00');

        try {
            $service->create($alpha->id(), Weekday::Wednesday, '18:00:00', '19:00:00');
            self::fail('un chevauchement avec le créneau d\'un autre groupe doit être refusé');
        } catch (OverlappingSlotException $e) {
            self::assertStringNotContainsString('The Office', $e->getMessage(), 'le message ne nomme pas l\'autre groupe');
        }
    }

    #[Test]

    public function testSlotsOfDifferentGroupsMayTouchOrSitOnAnotherDayOrReplaceADeletedSlot(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $alpha = $groupRepository->save(new Group(0, 'Alpha', null, null, 'alpha@example.test'));
        $beta = $groupRepository->save(new Group(0, 'Beta', null, null, 'beta@example.test'));
        $first = $service->create($beta->id(), Weekday::Wednesday, '18:30:00', '22:45:00');

        self::assertSame('18:30:00', $service->create($alpha->id(), Weekday::Wednesday, '14:00:00', '18:30:00')->endTime(), 'contigu : autorisé');
        self::assertSame('Thursday', $service->create($alpha->id(), Weekday::Thursday, '18:30:00', '22:45:00')->weekday()->name, 'un autre jour : autorisé');

        $service->delete($first->id());
        self::assertSame('20:00:00', $service->create($alpha->id(), Weekday::Wednesday, '20:00:00', '22:00:00')->startTime(), 'un créneau supprimé ne bloque plus');
    }

    #[Test]

    public function testUpdateCannotMakeASlotOverlapAnotherGroupsSlotButMayStayWithinItsOwnRange(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $alpha = $groupRepository->save(new Group(0, 'Alpha', null, null, 'alpha@example.test'));
        $beta = $groupRepository->save(new Group(0, 'Beta', null, null, 'beta@example.test'));
        $mine = $service->create($alpha->id(), Weekday::Wednesday, '14:00:00', '17:00:00');
        $service->create($beta->id(), Weekday::Wednesday, '18:00:00', '22:00:00');

        self::assertSame('17:30:00', $service->update($mine->id(), '14:00:00', '17:30:00')->endTime(), 'agrandir sans toucher un autre créneau : autorisé (et il ne se bloque pas lui-même)');

        $this->expectException(OverlappingSlotException::class);
        $service->update($mine->id(), '14:00:00', '19:00:00');
    }

    #[Test]

    public function testUpdateChangesSlotTimes(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $slot = $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $updated = $service->update($slot->id(), '19:00:00', '21:00:00');

        self::assertSame('19:00:00', $updated->startTime());
        self::assertSame('21:00:00', $updated->endTime());
    }

    #[Test]

    public function testUpdateWithEndBeforeStartThrowsInvalidArgument(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $slot = $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $this->expectException(\InvalidArgumentException::class);

        $service->update($slot->id(), '20:00:00', '19:00:00');
    }

    #[Test]

    public function testUpdateWithEndTimeAfterMaxCeilingThrowsInvalidArgument(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $slot = $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $this->expectException(\InvalidArgumentException::class);

        $service->update($slot->id(), '22:00:00', '23:45:00');
    }

    #[Test]

    public function testUpdateOnUnknownSlotThrowsInvalidArgument(): void
    {
        [$service] = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);

        $service->update(9999, '19:00:00', '21:00:00');
    }

    #[Test]

    public function testDeleteDeactivatesSlot(): void
    {
        [$service, $groupRepository, $slotRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $slot = $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $service->delete($slot->id());

        $found = $slotRepository->findById($slot->id());
        self::assertFalse($found->isActive());
    }

    #[Test]

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

    #[Test]

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

    #[Test]

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
        ?string $startTime = null,
        ?string $endTime = null,
    ): void {
        $requestingUser = $userRepository->save(new User(
            id: 0,
            email: uniqid('user-', true) . '@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]),
            displayName: 'Demandeur',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));

        $exception = $exceptionRepository->createRequest($holderSlotId, $occurrenceDate, $requestingGroupId, $requestingUser->id(), null, $startTime, $endTime);
        $exceptionRepository->respond($exception->id(), true, $requestingUser->id());
    }

    #[Test]

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

    #[Test]
    public function testAnAcceptedPartialRequestShowsOnlyTheRequestedHoursNotTheWholeHoldersSlot(): void
    {
        [$service, $groupRepository, , $exceptionRepository] = $this->makeService();
        $userRepository = new MysqlUserRepository($this->pdo);
        $holderGroup = $groupRepository->save(new Group(0, 'Groupe Titulaire', null, null, 'contact@example.test'));
        $holderSlot = $service->create($holderGroup->id(), Weekday::Tuesday, '18:30:00', '22:45:00');
        $requestingGroup = $groupRepository->save(new Group(0, 'Groupe Demandeur', null, null, 'contact@example.test'));
        $tuesday = (new \DateTimeImmutable('today'))->modify('monday this week')->modify('+1 day');
        $this->acceptExceptionForCurrentWeek($exceptionRepository, $userRepository, $holderSlot->id(), $requestingGroup->id(), $tuesday, '18:30:00', '19:00:00');

        [$card] = $service->findOccasionalPlanningSlots();

        self::assertSame('Groupe Demandeur', $card->groupName());
        self::assertSame(['18:30:00', '19:00:00'], [$card->slot()->startTime(), $card->slot()->endTime()], 'la plage demandée, pas 18h30–22h45');
        self::assertSame($holderSlot->id(), $card->slot()->id());
    }

    #[Test]
    public function testAValidatedFreeBookingOfTheWeekShowsAsAnOccasionalCardAndAPendingOneDoesNot(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $bookings = new \App\Repository\MysqlFreeSlotBookingRepository($this->pdo);
        $service = new SlotService(new MysqlRecurringSlotRepository($this->pdo), $groupRepository, new MysqlSlotExceptionRepository($this->pdo), $bookings);
        $group = $groupRepository->save(new Group(0, 'Groupe Réservant', null, null, 'contact@example.test'));
        $admin = (new MysqlUserRepository($this->pdo))->save(new User(0, 'admin@rehearsalbox.test', 'hash', 'Admin', UserRole::Admin, true, 0, null));
        $requester = new \App\Entity\Requester($group->id(), $admin->id());
        $wednesday = (new \DateTimeImmutable('today'))->modify('monday this week')->modify('+2 days');

        $validated = $bookings->create($requester, $wednesday, new \App\Entity\TimeRange('09:00:00', '14:00:00'), null);
        $bookings->decide($validated->id(), \App\Entity\Enum\FreeSlotBookingStatus::Validee, $admin->id(), null, new \DateTimeImmutable());
        $bookings->create($requester, $wednesday, new \App\Entity\TimeRange('15:00:00', '16:00:00'), null); // en attente
        $bookings->create($requester, $wednesday->modify('+8 days'), new \App\Entity\TimeRange('09:00:00', '10:00:00'), null); // semaine suivante

        $cards = $service->findOccasionalPlanningSlots();

        self::assertCount(1, $cards);
        self::assertSame('Groupe Réservant', $cards[0]->groupName());
        self::assertSame(Weekday::Wednesday, $cards[0]->slot()->weekday());
        self::assertSame(['09:00:00', '14:00:00'], [$cards[0]->slot()->startTime(), $cards[0]->slot()->endTime()]);
        self::assertFalse($cards[0]->isRecurring());
        self::assertSame($wednesday->format('Y-m-d'), $cards[0]->occurrenceDate()?->format('Y-m-d'));
    }

    #[Test]

    public function testFindFixedPlanningSlotsMarksSlotsAsRecurring(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $service->create($group->id(), Weekday::Tuesday, '18:00:00', '20:00:00');

        $planning = $service->findFixedPlanningSlots();

        self::assertTrue($planning[0]->isRecurring());
    }

    #[Test]

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
