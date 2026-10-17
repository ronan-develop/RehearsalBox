<?php

declare(strict_types=1);

namespace App\Accounting\Controller;

use App\Account\Entity\UserRole;
use App\Accounting\Entity\Member;
use App\Accounting\Import\ImportService;
use App\Accounting\Import\Rules\RuleService;
use App\Accounting\Import\Suggest\ImportReviewService;
use App\Accounting\Presenter\ImportView;
use App\Accounting\Service\Dues\CotisationService;
use App\Http\Request;
use App\Http\Response;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\View\TemplateRendererInterface;

/** Pages « Importer la banque » et « Règles des libellés » (#362), administrateurs uniquement. Rendu serveur ; tout passe ensuite par l'API. */
final class ImportPageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly AuthGuard $authGuard,
        private readonly ImportService $imports,
        private readonly ImportReviewService $review,
        private readonly RuleService $rules,
        private readonly CotisationService $dues,
        private readonly ImportView $view,
    ) {
    }

    public function index(Request $request): Response
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $reviewed = $this->review->review($admin);
        $members = [];
        foreach ($this->dues->memberNames($admin) as $id => $name) {
            $members[] = new Member($id, $name);
        }

        return new Response($this->renderer->render('admin/accounting/imports', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'page' => $this->view->page($reviewed['lines'], $reviewed['suggestions'], $members, $this->imports->savedProfile($admin)),
            'currentUserRole' => $admin->role(),
        ]));
    }

    public function rules(Request $request): Response
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);

        return new Response($this->renderer->render('admin/accounting/rules', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'page' => $this->view->rules($this->rules->list($admin)),
            'currentUserRole' => $admin->role(),
        ]));
    }
}
