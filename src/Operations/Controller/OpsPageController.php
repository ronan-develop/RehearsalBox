<?php

declare(strict_types=1);

namespace App\Operations\Controller;

use App\Http\ErrorPage;
use App\Http\Request;
use App\Http\Response;
use App\Metrics\Chart\SvgChart;
use App\Metrics\OperationsAccess;
use App\Operations\Backup\BackupHealthBuilder;
use App\Operations\Backup\ProtectionHealthBuilder;
use App\Operations\Production\ProductionReportBuilder;
use App\Security\AuthGuard;
use App\View\TemplateRendererInterface;

/**
 * Exploitation (#375) : état de la production, en lecture seule. Ouvert à tout administrateur ACTIF (OperationsAccess, décision du
 * propriétaire du 2026-10-17) : pour tous les autres, un visiteur, un musicien ou un administrateur désactivé, la page n'existe pas
 * (404 uniforme, aucun indice) et aucun lecteur n'est interrogé avant ce contrôle.
 */
final class OpsPageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly AuthGuard $authGuard,
        private readonly OperationsAccess $access,
        private readonly BackupHealthBuilder $backups,
        private readonly ProtectionHealthBuilder $protection,
        private readonly ProductionReportBuilder $production,
        private readonly \DateTimeZone $localTimezone,
    ) {
    }

    public function backups(Request $request): Response
    {
        return $this->page('admin/ops/backups', function (): array {
            $trend = $this->backups->dailyTrend();

            return [
                'dumpCards' => $this->backups->cards(),
                'protectionCards' => $this->protection->cards(),
                'trendChart' => $trend['values'] === [] ? '' : (new SvgChart())->bars('Taille des dumps quotidiens', 'Ko', $trend['labels'], $trend['values']),
                'recent' => $this->backups->recent(10),
            ];
        });
    }

    /** Onglet « Production » : release servie, déploiements, migrations, retour arrière. */
    public function production(Request $request): Response
    {
        return $this->page('admin/ops/production', function (): array {
            $durations = $this->production->durations();

            return [
                'cards' => $this->production->cards(),
                'durationChart' => $durations['values'] === [] ? '' : (new SvgChart())->bars('Durée des déploiements', 's', $durations['labels'], $durations['values']),
                'releases' => $this->production->releases(),
                'deployments' => $this->production->deployments(10),
            ];
        });
    }

    /** @param callable(): array<string, mixed> $data lu seulement APRÈS le contrôle d'accès */
    private function page(string $template, callable $data): Response
    {
        $user = $this->authGuard->currentUserOrNull();
        if ($user === null || !$this->access->allows($user)) {
            return ErrorPage::response(404);
        }

        return new Response($this->renderer->render($template, $data() + [
            'currentUserRole' => $user->role(),
            'localTimezone' => $this->localTimezone,
        ]));
    }
}
