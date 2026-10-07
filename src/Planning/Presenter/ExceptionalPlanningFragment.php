<?php

declare(strict_types=1);

namespace App\Planning\Presenter;

use App\Planning\Service\SlotServiceInterface;
use App\View\TemplateRendererInterface;

/**
 * Cartes « créneau exceptionnel » de la semaine, dessinées par le MÊME gabarit que le tableau de bord (#243) : après l'acceptation
 * d'une demande, le navigateur remplace le contenu du carrousel par ce HTML au lieu d'en reconstruire une copie en JavaScript.
 */
final class ExceptionalPlanningFragment
{
    public function __construct(
        private readonly SlotServiceInterface $slots,
        private readonly TemplateRendererInterface $renderer,
    ) {
    }

    /** @return array{html: string, count: int} */
    public function render(): array
    {
        $slots = $this->slots->findOccasionalPlanningSlots();

        return [
            'html' => $this->renderer->render('dashboard/_exceptional-cards', ['exceptionalPlanningSlots' => $slots]),
            'count' => \count($slots),
        ];
    }
}
