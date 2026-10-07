<?php

declare(strict_types=1);

namespace App\Metrics\Controller;

use App\Http\ErrorPage;
use App\Http\Request;
use App\Http\Response;
use App\Metrics\MetricsAccess;
use App\Metrics\Report\HealthReportBuilder;
use App\Metrics\Report\MetricsPeriod;
use App\Security\AuthGuard;
use App\View\TemplateRendererInterface;

/**
 * Tableau de bord des mesures, page « Santé et e-mails » (#196). Réservé à UN compte (MetricsAccess) : pour tous les autres,
 * y compris les autres administrateurs et les visiteurs non connectés, la page n'existe pas (404 uniforme, aucun indice).
 */
final class MetricsPageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly AuthGuard $authGuard,
        private readonly MetricsAccess $access,
        private readonly HealthReportBuilder $reports,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->authGuard->currentUserOrNull();
        if ($user === null || !$this->access->allows($user)) {
            return ErrorPage::response(404);
        }

        return new Response($this->renderer->render('admin/metrics/index', [
            'report' => $this->reports->build(MetricsPeriod::fromQuery($request->query('periode'))),
            'periods' => MetricsPeriod::cases(),
            'currentUserRole' => $user->role(),
        ]));
    }
}
