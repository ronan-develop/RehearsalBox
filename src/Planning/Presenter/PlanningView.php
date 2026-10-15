<?php

declare(strict_types=1);

namespace App\Planning\Presenter;

use App\Planning\Service\SlotServiceInterface;
use Symfony\Component\Clock\ClockInterface;

/** Planning du tableau de bord : les créneaux fixes et ceux de la semaine regroupés par jour à partir d'aujourd'hui (heure locale, #201, #461). */
final class PlanningView
{
    public function __construct(
        private readonly SlotServiceInterface $slots,
        private readonly PlanningDays $days,
        private readonly ClockInterface $clock,
        private readonly \DateTimeZone $timezone,
    ) {
    }

    /**
     * @param list<\App\Planning\Entity\RequestableSlot> $occasional créneaux occasionnels de la semaine (déjà lus par l'appelant, qui les affiche aussi à part)
     *
     * @return list<PlanningDay>
     */
    public function days(array $occasional): array
    {
        return $this->days->group($this->slots->findFixedPlanningSlots(), $this->clock->now()->setTimezone($this->timezone), $occasional);
    }
}
