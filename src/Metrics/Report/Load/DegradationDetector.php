<?php

declare(strict_types=1);

namespace App\Metrics\Report\Load;

use App\Metrics\Report\HealthStatus;

/**
 * Indicateur de dégradation (#198) : le site ralentit-il, ou renvoie-t-il des erreurs, PAR RAPPORT À SON USAGE ? Sans voir le
 * processeur du mutualisé, on compare les dernières heures à une base glissante d'heures de VOLUME ÉQUIVALENT (une soirée
 * chargée se compare à d'autres soirées chargées, pas à une nuit calme). Fonction pure, testée avec des séries simulées.
 *
 * - temps de réponse : orange à 1,5 fois la base, rouge à 2 fois (et au moins `minGapMs` de plus : une hausse de 3 à 6 ms n'est
 *   pas une dégradation) ;
 * - erreurs 5xx : rouge dès `minErrors` erreurs récentes dont le taux dépasse trois fois celui de la base (et 1 %), alors que le
 *   volume n'a pas grossi.
 * Pas assez d'historique comparable : « inconnu », jamais « normal » par défaut.
 */
final class DegradationDetector
{
    public function __construct(
        private readonly int $recentHours = 3,
        private readonly int $minComparableHours = 6,
        private readonly float $volumeTolerance = 0.5,
        private readonly float $warningRatio = 1.5,
        private readonly float $criticalRatio = 2.0,
        private readonly int $minGapMs = 20,
        private readonly int $minErrors = 3,
    ) {
    }

    /**
     * @param array<string, array{requests: int, errors: int, durationMs: int}> $hours heures UTC « Y-m-d H:00:00 » chronologiques ; les heures sans requête peuvent manquer
     */
    public function assess(array $hours): Degradation
    {
        $withTraffic = array_filter($hours, static fn (array $h): bool => $h['requests'] > 0);
        $recent = array_slice($withTraffic, -$this->recentHours, null, true);
        if ($recent === []) {
            return new Degradation(HealthStatus::Unknown, ['Aucun trafic récent à évaluer.']);
        }

        $recentRequests = array_sum(array_column($recent, 'requests'));
        $volume = $recentRequests / count($recent);
        $comparable = array_filter(
            array_diff_key($withTraffic, $recent),
            fn (array $h): bool => $h['requests'] >= $volume * (1 - $this->volumeTolerance) && $h['requests'] <= $volume * (1 + $this->volumeTolerance),
        );
        if (count($comparable) < $this->minComparableHours) {
            return new Degradation(HealthStatus::Unknown, ['Pas assez d\'historique à volume comparable (' . count($comparable) . ' heure(s), il en faut ' . $this->minComparableHours . ').']);
        }

        $baseRequests = array_sum(array_column($comparable, 'requests'));
        $recentAverage = array_sum(array_column($recent, 'durationMs')) / $recentRequests;
        $baseAverage = array_sum(array_column($comparable, 'durationMs')) / $baseRequests;
        $recentErrors = array_sum(array_column($recent, 'errors'));
        $recentRate = $recentErrors / $recentRequests;
        $baseRate = array_sum(array_column($comparable, 'errors')) / $baseRequests;

        $status = HealthStatus::Ok;
        $messages = [];
        $ratio = $baseAverage > 0 ? $recentAverage / $baseAverage : 1.0;
        if ($recentAverage - $baseAverage >= $this->minGapMs && $ratio >= $this->warningRatio) {
            $status = $ratio >= $this->criticalRatio ? HealthStatus::Critical : HealthStatus::Warning;
            $messages[] = sprintf('Temps de réponse moyen de %d ms, soit %s fois la base (%d ms) à volume comparable.', round($recentAverage), self::times($ratio), round($baseAverage));
        }
        if ($recentErrors >= $this->minErrors && $recentRate > max($baseRate * 3, 0.01)) {
            $status = HealthStatus::Critical;
            $messages[] = sprintf('%d erreurs serveur récentes (%s %% des requêtes) sans hausse de trafic, contre %s %% d\'habitude.', $recentErrors, self::percent($recentRate), self::percent($baseRate));
        }
        if ($messages === []) {
            $messages[] = sprintf('Stable : %d ms en moyenne, base de %d ms sur %d heures de volume comparable.', round($recentAverage), round($baseAverage), count($comparable));
        }

        return new Degradation($status, $messages);
    }

    private static function times(float $ratio): string
    {
        return number_format($ratio, 1, ',', ' ');
    }

    private static function percent(float $rate): string
    {
        return number_format($rate * 100, 1, ',', ' ');
    }
}
