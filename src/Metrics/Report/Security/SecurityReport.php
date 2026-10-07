<?php

declare(strict_types=1);

namespace App\Metrics\Report\Security;

use App\Metrics\Report\MetricsPeriod;
use App\Metrics\Report\StatusCard;

/** Ce que la page « Sécurité » affiche : compteurs, graphiques SVG déjà rendus et anomalies récentes. */
final class SecurityReport
{
    /**
     * @param list<StatusCard> $cards
     * @param list<string>     $charts
     * @param list<Anomaly>    $anomalies
     */
    public function __construct(
        public readonly MetricsPeriod $period,
        public readonly array $cards,
        public readonly array $charts,
        public readonly array $anomalies,
    ) {
    }
}
