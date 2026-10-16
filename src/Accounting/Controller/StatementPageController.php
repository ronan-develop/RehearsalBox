<?php

declare(strict_types=1);

namespace App\Accounting\Controller;

use App\Account\Entity\UserRole;
use App\Accounting\Presenter\StatementListView;
use App\Accounting\Service\FiscalYearService;
use App\Accounting\Service\StatementService;
use App\Http\FileResponse;
use App\Http\Request;
use App\Http\Response;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Support\StrictId;
use App\View\TemplateRendererInterface;

/**
 * Pages des relevés de compte (#362), administrateurs uniquement. Liste d'un exercice (celui de l'adresse s'il existe, sinon le dernier) avec
 * l'écart de chaque relevé, et téléchargement : toujours en PIÈCE JOINTE (même une image), jamais interprété par le navigateur (`nosniff`,
 * CSP `sandbox`), jamais mis en cache, envoyé en flux ; un fichier absent est un 404, jamais une réponse vide.
 */
final class StatementPageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly AuthGuard $authGuard,
        private readonly FiscalYearService $years,
        private readonly StatementService $statements,
        private readonly StatementListView $view,
    ) {
    }

    public function index(Request $request): Response
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $years = $this->years->list($admin);
        $year = YearChooser::choose($years, StrictId::from($request->query('year')));

        return new Response($this->renderer->render('admin/accounting/statements', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'page' => $this->view->page($years, $year, $year === null ? [] : $this->statements->review($admin, $year->id)),
            'currentUserRole' => $admin->role(),
        ]));
    }

    public function download(Request $request, string $id): Response
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $statement = $this->statements->download($admin, StrictId::orDenied($id));
        $name = $statement->originalName;
        $fallback = trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name));

        return new FileResponse($this->statements->pathOf($admin, $statement->id), $statement->mime, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $fallback !== '' ? $fallback : 'releve', rawurlencode($name)),
        ]);
    }
}
