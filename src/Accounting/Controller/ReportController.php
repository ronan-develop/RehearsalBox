<?php

declare(strict_types=1);

namespace App\Accounting\Controller;

use App\Account\Entity\UserRole;
use App\Accounting\Export\CsvResponse;
use App\Accounting\Export\LedgerExportService;
use App\Accounting\Exception\FiscalYearNotFoundException;
use App\Accounting\Presenter\YearReportView;
use App\Accounting\Service\Dues\CotisationService;
use App\Accounting\Service\EntryService;
use App\Accounting\Service\FiscalYearService;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Response;
use App\Security\AuthGuard;
use App\Support\StrictId;
use App\View\TemplateRendererInterface;

/**
 * Sorties de la comptabilité (#362), administrateurs uniquement, toutes en lecture (`GET`, aucun effet de bord) : rapport financier imprimable
 * d'un exercice (art. 11), export CSV complet (art. 13) et chiffres à reprendre dans le compte rendu d'assemblée. Identifiant strictement
 * numérique (sinon refus comme un accès interdit) ; exercice inconnu : 404 sans répéter l'identifiant. Nom du fichier d'export fixé par le serveur.
 */
final class ReportController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly AuthGuard $authGuard,
        private readonly FiscalYearService $years,
        private readonly EntryService $entries,
        private readonly CotisationService $dues,
        private readonly LedgerExportService $export,
        private readonly YearReportView $view,
        private readonly string $associationName,
    ) {
    }

    public function report(Request $request, string $id): Response
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $summary = $this->years->summary($admin, StrictId::orDenied($id));

        return new Response($this->renderer->render('admin/accounting/report', [
            'report' => $this->view->build($summary['year'], $summary['totals'], $this->entries->list($admin, $summary['year']->id), $this->dues->memberNames($admin), $this->associationName),
            'backHref' => '/admin/accounting?year=' . $summary['year']->id,
        ]));
    }

    public function csv(Request $request, string $id): Response
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $yearId = StrictId::orDenied($id);

        return new CsvResponse($this->export->rows($admin, $yearId), 'comptabilite-exercice-' . $yearId . '.csv');
    }

    /** Chiffres de l'exercice demandé (`year`) ou, à défaut, du plus récent, en texte brut. */
    public function figures(Request $request): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $requested = $request->query('year');
        $yearId = $requested === null ? ($this->years->list($admin)[0]->id ?? null) : StrictId::orDenied($requested);
        if ($yearId === null) {
            throw new FiscalYearNotFoundException();
        }
        $summary = $this->years->summary($admin, $yearId);

        return new JsonResponse(['label' => $summary['year']->label, 'text' => $this->view->figuresText($summary['year'], $summary['totals'])]);
    }
}
