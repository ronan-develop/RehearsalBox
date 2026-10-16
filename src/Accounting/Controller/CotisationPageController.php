<?php

declare(strict_types=1);

namespace App\Accounting\Controller;

use App\Account\Entity\UserRole;
use App\Accounting\Presenter\CotisationView;
use App\Accounting\Service\Dues\CotisationService;
use App\Accounting\Service\FiscalYearService;
use App\Http\Request;
use App\Http\Response;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Support\StrictId;
use App\View\TemplateRendererInterface;

/** Page admin « Cotisations » (#362) : tableau adhérents × mois d'un exercice, calculé à partir du livre. Rendu serveur ; les saisies passent par l'API. */
final class CotisationPageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly AuthGuard $authGuard,
        private readonly FiscalYearService $years,
        private readonly CotisationService $dues,
        private readonly CotisationView $view,
    ) {
    }

    public function index(Request $request): Response
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $years = $this->years->list($admin);
        $year = YearChooser::choose($years, StrictId::from($request->query('year')));

        return new Response($this->renderer->render('admin/accounting/cotisations', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'page' => $this->view->page($years, $year, $year === null ? null : $this->dues->table($admin, $year, new \DateTimeImmutable('today'))),
            'currentUserRole' => $admin->role(),
        ]));
    }
}
