<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Presenter\DashboardBookings;
use App\Presenter\DashboardView;
use App\Presenter\PlanningDays;
use App\Presenter\PlanningView;
use App\Repository\MysqlBookingDateLock;
use App\Repository\MysqlFreeSlotBookingRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Repository\MysqlSlotExceptionRepository;
use App\Service\AvailabilityService;
use App\Service\FreeSlotBookingPolicy;
use App\Service\FreeSlotBookingService;
use App\Service\SlotService;
use Symfony\Component\Clock\MockClock;

/** Données du tableau de bord câblées sur la base de test (#239), à une date fixe. */
final class TestDashboard
{
    public static function view(\PDO $pdo, string $now = '2026-10-06 12:00:00'): DashboardView
    {
        $groups = new MysqlGroupRepository($pdo);
        $slots = new MysqlRecurringSlotRepository($pdo);
        $exceptions = new MysqlSlotExceptionRepository($pdo);
        $clock = new MockClock($now);
        $slotService = new SlotService($slots, $groups, $exceptions);

        return new DashboardView(
            new AvailabilityService($exceptions, $groups, $slots),
            $groups,
            $slotService,
            new PlanningView($slotService, new PlanningDays(), $clock, new \DateTimeZone('Europe/Paris')),
            new DashboardBookings(new FreeSlotBookingService(new MysqlFreeSlotBookingRepository($pdo), new MysqlBookingDateLock($pdo), $slots, $groups, new FreeSlotBookingPolicy(), $clock)),
        );
    }
}
