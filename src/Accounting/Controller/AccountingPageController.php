<?php

declare(strict_types=1);

namespace App\Accounting\Controller;

use App\Account\Entity\UserRole;
use App\Accounting\Presenter\AccountingJournalView;
use App\Accounting\Service\Dues\CotisationService;
use App\Accounting\Notification\TreasurerReminderService;
use App\Accounting\Presenter\TodoView;
use App\Accounting\Service\EntryService;
use App\Accounting\Service\FiscalYearService;
use App\Http\Request;
use App\Http\Response;
use App\Accounting\Todo\TodoService;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Support\StrictId;
use App\View\TemplateRendererInterface;

/**
 * Page admin « Comptabilité » (#362) : l'exercice choisi (par défaut le dernier), ses soldes et son journal, avec un panneau de saisie. Rendu
 * serveur ; les écritures passent par l'API. Un exercice inconnu ou mal écrit dans l'adresse est ignoré (on ouvre l'exercice par défaut) ;
 * les filtres sont des listes blanches.
 */
final class AccountingPageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly AuthGuard $authGuard,
        private readonly FiscalYearService $years,
        private readonly EntryService $entries,
        private readonly AccountingJournalView $view,
        private readonly CotisationService $dues,
        private readonly TodoService $todo,
        private readonly TodoView $todoView,
        private readonly TreasurerReminderService $reminders,
    ) {
    }

    public function index(Request $request): Response
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $years = $this->years->list($admin);
        $year = YearChooser::choose($years, StrictId::from($request->query('year')));
        $summary = $year === null ? null : $this->years->summary($admin, $year->id);

        return new Response($this->renderer->render('admin/accounting/index', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'page' => $this->view->page(
                $years,
                $year,
                $summary['totals'] ?? null,
                $year === null ? [] : $this->entries->list($admin, $year->id),
                is_string($request->query('direction')) ? $request->query('direction') : '',
                is_string($request->query('category')) ? $request->query('category') : '',
                $this->dues->memberNames($admin),
            ),
            'todo' => $year === null ? null : $this->todoView->build($this->todo->forYear($admin, $year, new \DateTimeImmutable('today')), $year->id),
            'reminderProblem' => $this->reminders->recipientProblem(),
            'currentUserRole' => $admin->role(),
        ]));
    }
}
