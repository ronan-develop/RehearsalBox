<?php

declare(strict_types=1);

namespace App\Planning\Presenter;

use App\Planning\Service\SlotServiceInterface;
use Symfony\Component\Clock\ClockInterface;

/** Planning fixe du tableau de bord : les créneaux regroupés par jour à partir d'aujourd'hui (heure locale, #201). */
final class PlanningView
{
    public function __construct(
        private readonly SlotServiceInterface $slots,
        private readonly PlanningDays $days,
        private readonly ClockInterface $clock,
        private readonly \DateTimeZone $timezone,
    ) {
    }

    /** @return list<PlanningDay> */
    public function fixedDays(): array
    {
        return $this->days->group($this->slots->findFixedPlanningSlots(), $this->clock->now()->setTimezone($this->timezone));
    }
}
