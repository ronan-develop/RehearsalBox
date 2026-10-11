<?php

declare(strict_types=1);

namespace App\Operations\Controller;

use App\Http\ErrorPage;
use App\Http\Request;
use App\Http\Response;
use App\Metrics\Chart\SvgChart;
use App\Metrics\MetricsAccess;
use App\Operations\Backup\BackupHealthBuilder;
use App\Operations\Backup\ProtectionHealthBuilder;
use App\Security\AuthGuard;
use App\View\TemplateRendererInterface;

/**
 * Exploitation (#375) : état de la production, en lecture seule. Réservé au même UNIQUE compte que les mesures (MetricsAccess) :
 * pour tous les autres, y compris un autre administrateur, un visiteur ou une adresse non configurée, la page n'existe pas
 * (404 uniforme, aucun indice) et aucun lecteur n'est interrogé avant ce contrôle.
 */
final class OpsPageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly AuthGuard $authGuard,
        private readonly MetricsAccess $access,
        private readonly BackupHealthBuilder $backups,
        private readonly ProtectionHealthBuilder $protection,
        private readonly \DateTimeZone $localTimezone,
    ) {
    }

    public function backups(Request $request): Response
    {
        $user = $this->authGuard->currentUserOrNull();
        if ($user === null || !$this->access->allows($user)) {
            return ErrorPage::response(404);
        }

        $trend = $this->backups->dailyTrend();

        return new Response($this->renderer->render('admin/ops/backups', [
            'dumpCards' => $this->backups->cards(),
            'protectionCards' => $this->protection->cards(),
            'trendChart' => $trend['values'] === [] ? '' : (new SvgChart())->bars('Taille des dumps quotidiens', 'Ko', $trend['labels'], $trend['values']),
            'recent' => $this->backups->recent(10),
            'currentUserRole' => $user->role(),
            'localTimezone' => $this->localTimezone,
        ]));
    }
}
