<?php

declare(strict_types=1);

namespace App\Metrics\Report;

/** Ce que la page « Santé et e-mails » affiche : des cartes d'état et des graphiques SVG déjà rendus. */
final class HealthReport
{
    /**
     * @param list<StatusCard> $cards
     * @param list<string>     $charts SVG des graphiques (HTML déjà échappé par SvgChart)
     */
    public function __construct(
        public readonly MetricsPeriod $period,
        public readonly array $cards,
        public readonly array $charts,
    ) {
    }
}
