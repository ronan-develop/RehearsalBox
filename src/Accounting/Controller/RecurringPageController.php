<?php

declare(strict_types=1);

namespace App\Accounting\Controller;

use App\Account\Entity\UserRole;
use App\Accounting\Presenter\RecurringView;
use App\Accounting\Service\FiscalYearService;
use App\Accounting\Service\Recurring\RecurringOccurrenceService;
use App\Accounting\Service\Recurring\RecurringTemplateService;
use App\Http\Request;
use App\Http\Response;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Support\StrictId;
use App\View\TemplateRendererInterface;

/** Page admin « Écritures récurrentes » (#362) : échéances à confirmer de l'exercice choisi et modèles. Rendu serveur ; les décisions passent par l'API. */
final class RecurringPageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly AuthGuard $authGuard,
        private readonly FiscalYearService $years,
        private readonly RecurringTemplateService $templates,
        private readonly RecurringOccurrenceService $occurrences,
        private readonly RecurringView $view,
    ) {
    }

    public function index(Request $request): Response
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $year = YearChooser::choose($this->years->list($admin), StrictId::from($request->query('year')));

        return new Response($this->renderer->render('admin/accounting/recurring', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'page' => $this->view->page(
                $year === null ? [] : $this->occurrences->pending($admin, $year, new \DateTimeImmutable('today')),
                $this->templates->list($admin),
                $year?->isOpen() ?? false,
            ),
            'yearId' => $year?->id,
            'currentUserRole' => $admin->role(),
        ]));
    }
}
