<?php

declare(strict_types=1);

namespace App\Metrics\Report;

/**
 * Seuils vert / orange / rouge des cartes d'état, fixés en configuration (`metrics.thresholds`, #196) : chaque entrée est
 * `[seuil orange, seuil rouge]`. Pour une mesure « plus c'est haut, pire c'est » (`higherIsWorse`), orange dès que la valeur
 * atteint le premier seuil, rouge dès qu'elle atteint le second ; pour l'inverse (disponibilité, espace libre), c'est quand
 * elle tombe sous le seuil.
 */
final class Thresholds
{
    public const DEFAULTS = [
        'availability_percent' => [99.5, 98.0],
        'errors_5xx' => [1, 10],
        'response_ms' => [500, 1500],
        'mail_failures' => [1, 5],
        'cron_minutes' => [90, 180],
        'backup_hours' => [30, 54],
        'disk_free_mb' => [1024, 256],
    ];

    /** @var array<string, array{0: float|int, 1: float|int}> */
    private readonly array $values;

    /** @param array<string, mixed> $configured surcharges venant de config.local.php (les clés inconnues sont ignorées) */
    public function __construct(array $configured = [])
    {
        $values = self::DEFAULTS;
        foreach ($configured as $name => $pair) {
            if (isset($values[$name]) && is_array($pair) && count($pair) === 2 && is_numeric($pair[0] ?? null) && is_numeric($pair[1] ?? null)) {
                $values[$name] = [$pair[0] + 0, $pair[1] + 0];
            }
        }
        $this->values = $values;
    }

    public function status(string $metric, float|int|null $value, bool $higherIsWorse): HealthStatus
    {
        if ($value === null || !isset($this->values[$metric])) {
            return HealthStatus::Unknown;
        }
        [$warning, $critical] = $this->values[$metric];

        if ($higherIsWorse) {
            return $value >= $critical ? HealthStatus::Critical : ($value >= $warning ? HealthStatus::Warning : HealthStatus::Ok);
        }

        return $value < $critical ? HealthStatus::Critical : ($value < $warning ? HealthStatus::Warning : HealthStatus::Ok);
    }
}
