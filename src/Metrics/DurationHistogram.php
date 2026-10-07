<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * Répartition des durées de requête en six tranches (≤ 50, 100, 250, 500, 1000 ms, au-delà) : de quoi estimer la médiane et le
 * 95e centile à partir d'agrégats, sans garder les durées une par une. L'estimation est la BORNE HAUTE de la tranche qui contient
 * le centile (« au plus 250 ms ») ; la dernière tranche est ouverte (`OPEN_ENDED`).
 */
final class DurationHistogram
{
    /** Bornes hautes des cinq premières tranches, en millisecondes. */
    public const BOUNDS = [50, 100, 250, 500, 1000];

    /** Nombre de tranches (la dernière est ouverte). */
    public const BUCKETS = 6;

    /** Valeur renvoyée quand le centile tombe dans la tranche ouverte (« plus d'une seconde »). */
    public const OPEN_ENDED = 1001;

    /** Index de la tranche d'une durée. */
    public static function bucketOf(int $durationMs): int
    {
        foreach (self::BOUNDS as $index => $bound) {
            if ($durationMs <= $bound) {
                return $index;
            }
        }

        return self::BUCKETS - 1;
    }

    /**
     * @param list<int> $counts effectifs des six tranches
     * @param float     $fraction 0.5 pour la médiane, 0.95 pour le 95e centile
     *
     * @return int|null borne haute de la tranche, OPEN_ENDED au-delà d'une seconde ; null sans aucune mesure
     */
    public static function percentile(array $counts, float $fraction): ?int
    {
        $total = array_sum($counts);
        if ($total <= 0) {
            return null;
        }

        $target = (int) ceil($total * $fraction);
        $seen = 0;
        foreach ($counts as $index => $count) {
            $seen += $count;
            if ($seen >= $target) {
                return self::BOUNDS[$index] ?? self::OPEN_ENDED;
            }
        }

        return self::OPEN_ENDED;
    }
}
