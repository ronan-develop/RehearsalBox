<?php

declare(strict_types=1);

namespace App\Tests\Dashboard;

use App\Entity\Enum\UserRole;
use App\Planning\Entity\Weekday;
use App\Group\Entity\Group;
use App\Entity\User;
use App\Dashboard\DashboardBookings;
use App\Dashboard\DashboardView;
use App\Planning\Presenter\PlanningDays;
use App\Planning\Presenter\PlanningView;
use App\Group\Repository\GroupRepositoryInterface;
use App\Planning\Repository\MysqlBookingDateLock;
use App\Planning\Repository\MysqlFreeSlotBookingRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Planning\Repository\MysqlRecurringSlotRepository;
use App\Planning\Repository\MysqlSlotExceptionRepository;
use App\Repository\MysqlUserRepository;
use App\Planning\Service\AvailabilityService;
use App\Planning\Service\FreeSlotBookingPolicy;
use App\Planning\Service\FreeSlotBookingService;
use App\Planning\Service\SlotService;
use App\Tests\RepositoryTestCase;
use App\Tests\Support\CountingGroupRepository;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

/** #239 : les données du tableau de bord se construisent sans requête en boucle. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class DashboardViewTest extends RepositoryTestCase
{
    /** @param GroupRepositoryInterface $groups celui que la vue lit en direct (les services ont le leur, pour ne compter que les lectures de la vue) */
    private function viewOver(GroupRepositoryInterface $groups): DashboardView
    {
        $slots = new MysqlRecurringSlotRepository($this->pdo);
        $exceptions = new MysqlSlotExceptionRepository($this->pdo);
        $plain = new MysqlGroupRepository($this->pdo);
        $slotService = new SlotService($slots, $plain, $exceptions);
        $clock = new MockClock('2026-10-06 12:00:00');

        return new DashboardView(
            new AvailabilityService($exceptions, $plain, $slots),
            $groups,
            $slotService,
            new PlanningView($slotService, new PlanningDays(), $clock, new \DateTimeZone('Europe/Paris')),
            new DashboardBookings(new FreeSlotBookingService(new MysqlFreeSlotBookingRepository($this->pdo), new MysqlBookingDateLock($this->pdo), $slots, $plain, new FreeSlotBookingPolicy(), $clock)),
        );
    }

    #[Test]
    public function testEachRequestingGroupIsReadOnlyOnceHoweverManyRequestsItSent(): void
    {
        $groups = new MysqlGroupRepository($this->pdo);
        $slots = new MysqlRecurringSlotRepository($this->pdo);
        $exceptions = new MysqlSlotExceptionRepository($this->pdo);
        $users = new MysqlUserRepository($this->pdo);
        $alice = $users->save(new User(0, 'alice@rehearsalbox.test', 'x', 'Alice', UserRole::Musicien, true, 0, null));
        $bob = $users->save(new User(0, 'bob@rehearsalbox.test', 'x', 'Bob', UserRole::Musicien, true, 0, null));
        $alpha = $groups->save(new Group(0, 'Alpha', null, null, 'alpha@example.test'));
        $beta = $groups->save(new Group(0, 'Beta', null, null, 'beta@example.test'));
        $groups->addMember($alpha->id(), $alice->id());
        $groups->addMember($beta->id(), $bob->id());
        $slot = (new SlotService($slots, $groups, $exceptions))->create($alpha->id(), Weekday::Wednesday, '18:00:00', '22:00:00');
        foreach (['2026-10-14', '2026-10-21', '2026-10-28'] as $date) {
            $exceptions->createRequest($slot->id(), new \DateTimeImmutable($date), $beta->id(), $bob->id(), null);
        }
        $counting = new CountingGroupRepository($groups);
        $view = $this->viewOver($counting);
        $counting->findByIdCalls = 0;

        $data = $view->for($alice);

        self::assertCount(3, $data['receivedExceptions']);
        self::assertLessThanOrEqual(1, $counting->findByIdCalls, 'Beta lu une fois, Alpha (le titulaire) est déjà connu : pas une lecture par demande');
    }

    #[Test]
    public function testTheViewExposesTheGroupNameTheRolesAndTheInitialsForTheHeader(): void
    {
        $groups = new MysqlGroupRepository($this->pdo);
        $users = new MysqlUserRepository($this->pdo);
        $alice = $users->save(new User(0, 'alice@rehearsalbox.test', 'x', 'Alice Martin', UserRole::Musicien, true, 0, null));
        $alpha = $groups->save(new Group(0, 'Alpha', null, null, 'alpha@example.test'));
        $groups->addMember($alpha->id(), $alice->id());

        $data = $this->viewOver($groups)->for($alice);

        self::assertSame('Alpha', $data['currentUserGroupName']);
        self::assertSame('AM', $data['currentUserInitials']);
        self::assertArrayHasKey($alpha->id(), $data['currentUserGroupRoles']);
        self::assertSame([], $data['sentExceptions']);
    }
}
