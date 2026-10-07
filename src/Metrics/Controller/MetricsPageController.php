<?php

declare(strict_types=1);

namespace App\Metrics\Controller;

use App\Http\ErrorPage;
use App\Http\Request;
use App\Http\Response;
use App\Metrics\MetricsAccess;
use App\Metrics\Report\HealthReportBuilder;
use App\Metrics\Report\Load\LoadReportBuilder;
use App\Metrics\Report\MetricsPeriod;
use App\Metrics\Report\Security\SecurityReportBuilder;
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
        private readonly SecurityReportBuilder $securityReports,
        private readonly LoadReportBuilder $loadReports,
        private readonly \DateTimeZone $localTimezone,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->page($request, 'admin/metrics/index', fn (MetricsPeriod $period): array => ['report' => $this->reports->build($period)]);
    }

    /** Page « Sécurité et anomalies » (#197). */
    public function security(Request $request): Response
    {
        return $this->page($request, 'admin/metrics/security', fn (MetricsPeriod $period): array => ['report' => $this->securityReports->build($period)]);
    }

    /** Page « Charge et dégradation » (#198). */
    public function load(Request $request): Response
    {
        return $this->page($request, 'admin/metrics/load', fn (MetricsPeriod $period): array => ['report' => $this->loadReports->build($period)]);
    }

    /** @param callable(MetricsPeriod): array<string, mixed> $data */
    private function page(Request $request, string $template, callable $data): Response
    {
        $user = $this->authGuard->currentUserOrNull();
        if ($user === null || !$this->access->allows($user)) {
            return ErrorPage::response(404);
        }

        return new Response($this->renderer->render($template, $data(MetricsPeriod::fromQuery($request->query('periode'))) + [
            'periods' => MetricsPeriod::cases(),
            'currentUserRole' => $user->role(),
            'localTimezone' => $this->localTimezone,
        ]));
    }
}
