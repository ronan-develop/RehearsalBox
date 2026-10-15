<?php

declare(strict_types=1);

namespace App\Planning\Presenter;

use App\Group\Repository\GroupRepositoryInterface;
use App\Planning\Service\SlotServiceInterface;
use App\View\TemplateRendererInterface;

/**
 * Cartes « créneau exceptionnel » et piste du planning, dessinées par les MÊMES gabarits que le tableau de bord (#243, #461) : après
 * l'acceptation d'une demande, le navigateur remplace le contenu du carrousel et celui du planning par ce HTML au lieu d'en
 * reconstruire une copie en JavaScript.
 */
final class ExceptionalPlanningFragment
{
    public function __construct(
        private readonly SlotServiceInterface $slots,
        private readonly TemplateRendererInterface $renderer,
        private readonly PlanningView $planning,
        private readonly GroupRepositoryInterface $groups,
    ) {
    }

    /** @return array{html: string, count: int, planningHtml: string} */
    public function render(int $userId): array
    {
        $slots = $this->slots->findOccasionalPlanningSlots();
        $roles = [];
        foreach ($this->groups->findByMember($userId) as $group) {
            $roles[$group->id()] = $this->groups->roleOf($group->id(), $userId);
        }

        return [
            'html' => $this->renderer->render('dashboard/_exceptional-cards', ['exceptionalPlanningSlots' => $slots]),
            'count' => \count($slots),
            'planningHtml' => $this->renderer->render('dashboard/_planning-track', ['planningDays' => $this->planning->days($slots), 'currentUserGroupRoles' => $roles]),
        ];
    }
}
