<?php

declare(strict_types=1);

namespace App\Metrics\Report\Load;

use App\Metrics\Report\MetricsPeriod;
use App\Metrics\Report\StatusCard;

/** Ce que la page « Charge » affiche : cartes, graphiques SVG déjà rendus, routes les plus sollicitées et verdict de dégradation. */
final class LoadReport
{
    /**
     * @param list<StatusCard>                                                                                   $cards
     * @param list<string>                                                                                       $charts
     * @param list<array{route: string, requests: int, averageMs: int, maxMs: int, timeShare: int, polling: bool}> $routes
     */
    public function __construct(
        public readonly MetricsPeriod $period,
        public readonly array $cards,
        public readonly array $charts,
        public readonly array $routes,
        public readonly Degradation $degradation,
    ) {
    }
}
