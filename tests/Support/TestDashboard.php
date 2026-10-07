<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Dashboard\DashboardBookings;
use App\Dashboard\DashboardView;
use App\Planning\Presenter\PlanningDays;
use App\Planning\Presenter\PlanningView;
use App\Planning\Repository\MysqlBookingDateLock;
use App\Planning\Repository\MysqlFreeSlotBookingRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Planning\Repository\MysqlRecurringSlotRepository;
use App\Planning\Repository\MysqlSlotExceptionRepository;
use App\Planning\Service\AvailabilityService;
use App\Planning\Service\FreeSlotBookingPolicy;
use App\Planning\Service\FreeSlotBookingService;
use App\Planning\Service\SlotService;
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
