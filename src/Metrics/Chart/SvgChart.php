<?php

declare(strict_types=1);

namespace App\Metrics\Chart;

/**
 * Graphiques en SVG rendus par le serveur (#196) : aucune bibliothèque, lisibles sans JavaScript. Chaque graphique est
 * accessible (titre et résumé lus par les lecteurs d'écran, tableau de valeurs repliable) et suit les règles de dataviz du
 * projet : une seule couleur par graphique, barres fines à bout arrondi, courbe de 2 px, grille discrète, axe unique. Les
 * couleurs sont des classes CSS (`.rb-chart-*`, jetons du thème) : rien n'est écrit en dur dans le SVG.
 */
final class SvgChart
{
    private const WIDTH = 420;
    private const HEIGHT = 180;
    private const LEFT = 44;
    private const RIGHT = 8;
    private const TOP = 10;
    private const BOTTOM = 26;
    private const BAR_MAX = 24;
    private const MAX_X_LABELS = 5;

    private int $sequence = 0;

    /**
     * @param list<string>    $labels une étiquette par point (ex. « 14 h », « 07/10 »)
     * @param list<int|float> $values une valeur par point
     * @param string          $tone  « accent » (par défaut) ou « err » pour un compte d'erreurs
     */
    public function bars(string $title, string $unit, array $labels, array $values, string $tone = 'accent'): string
    {
        return $this->figure($title, $unit, $labels, $values, static function (array $x, array $y, float $baseline, float $band) use ($tone): string {
            $svg = '';
            $width = min(self::BAR_MAX, max(1.0, $band - 2));
            foreach ($y as $i => $top) {
                if ($top >= $baseline) {
                    continue;
                }
                $left = $x[$i] - $width / 2;
                $radius = min(4.0, $width / 2, $baseline - $top);
                // Rond au bout de la donnée (en haut), carré sur la base.
                $svg .= sprintf(
                    '<path class="rb-chart-bar rb-chart-bar--%s" d="M%.1f %.1f V%.1f a%.1f %.1f 0 0 1 %.1f %.1f H%.1f a%.1f %.1f 0 0 1 %.1f %.1f V%.1f Z"/>',
                    $tone,
                    $left,
                    $baseline,
                    $top + $radius,
                    $radius,
                    $radius,
                    $radius,
                    -$radius,
                    $left + $width - $radius,
                    $radius,
                    $radius,
                    $radius,
                    $radius,
                    $baseline,
                );
            }

            return $svg;
        });
    }

    /**
     * @param list<string>    $labels
     * @param list<int|float> $values
     */
    public function line(string $title, string $unit, array $labels, array $values): string
    {
        return $this->figure($title, $unit, $labels, $values, static function (array $x, array $y, float $baseline): string {
            if ($x === []) {
                return '';
            }
            $points = [];
            foreach ($y as $i => $top) {
                $points[] = sprintf('%.1f,%.1f', $x[$i], $top);
            }
            $svg = count($points) > 1 ? '<polyline class="rb-chart-line" points="' . implode(' ', $points) . '"/>' : '';
            $last = count($points) - 1;

            // Point final : un seul point (valeur unique) ou fin de courbe, entouré d'un anneau de la couleur du fond.
            return $svg . sprintf('<circle class="rb-chart-dot" cx="%.1f" cy="%.1f" r="4"/>', $x[$last], $y[$last]);
        });
    }

    /**
     * @param list<string>    $labels
     * @param list<int|float> $values
     * @param callable(list<float>, list<float>, float, float): string $marks dessine les marques (abscisses, ordonnées, base, largeur de bande)
     */
    private function figure(string $title, string $unit, array $labels, array $values, callable $marks): string
    {
        $id = 'rb-chart-' . ++$this->sequence;
        $count = count($values);
        $max = $values === [] ? 0 : max($values);
        $top = self::niceMax($max);
        $plotLeft = (float) self::LEFT;
        $plotRight = (float) (self::WIDTH - self::RIGHT);
        $plotTop = (float) self::TOP;
        $baseline = (float) (self::HEIGHT - self::BOTTOM);
        $band = $count === 0 ? 0.0 : ($plotRight - $plotLeft) / $count;

        $x = [];
        $y = [];
        foreach ($values as $i => $value) {
            $x[] = $plotLeft + $band * ($i + 0.5);
            $y[] = $baseline - ($baseline - $plotTop) * ($top === 0 ? 0 : $value / $top);
        }

        $svg = '';
        // Peu de valeurs entières : deux repères suffisent (pas de « 0,5 e-mail ») ; sans donnée, la seule base.
        $fractions = $top === 0 ? [0] : (($top <= 2 && $top == floor($top)) ? [0, 1] : [0, 0.5, 1]);
        foreach ($fractions as $fraction) {
            $lineY = $baseline - ($baseline - $plotTop) * $fraction;
            $svg .= sprintf('<line class="rb-chart-grid" x1="%.1f" x2="%.1f" y1="%.1f" y2="%.1f"/>', $plotLeft, $plotRight, $lineY, $lineY);
            $svg .= sprintf('<text class="rb-chart-tick" x="%.1f" y="%.1f" text-anchor="end">%s</text>', $plotLeft - 6, $lineY + 4, self::e(self::format($top * $fraction)));
        }
        $svg .= $marks($x, $y, $baseline, $band);
        foreach (self::labelIndexes($count) as $i) {
            $svg .= sprintf('<text class="rb-chart-tick" x="%.1f" y="%.1f" text-anchor="middle">%s</text>', $x[$i], $baseline + 17, self::e($labels[$i] ?? ''));
        }

        $summary = $count === 0 || $max == 0
            ? 'Aucune donnée sur la période.'
            : sprintf('%d points, maximum %s %s, total %s %s.', $count, self::format($max), $unit, self::format(array_sum($values)), $unit);

        return sprintf(
            '<figure class="rb-chart"><figcaption>%s</figcaption>'
            . '<svg class="rb-chart-svg" viewBox="0 0 %d %d" role="img" aria-labelledby="%s-t %s-d" preserveAspectRatio="xMidYMid meet">'
            . '<title id="%s-t">%s</title><desc id="%s-d">%s</desc>%s</svg>%s%s</figure>',
            self::e($title),
            self::WIDTH,
            self::HEIGHT,
            $id,
            $id,
            $id,
            self::e($title),
            $id,
            self::e($summary),
            $svg,
            $max == 0 ? '<p class="rb-chart-empty">Aucune donnée sur la période.</p>' : '',
            self::table($title, $unit, $labels, $values),
        );
    }

    /**
     * @param list<string>    $labels
     * @param list<int|float> $values
     */
    private static function table(string $title, string $unit, array $labels, array $values): string
    {
        if ($values === []) {
            return '';
        }
        $rows = '';
        foreach ($values as $i => $value) {
            $rows .= sprintf('<tr><th scope="row">%s</th><td>%s</td></tr>', self::e($labels[$i] ?? ''), self::e(self::format($value)));
        }

        return sprintf(
            '<details class="rb-chart-table"><summary>Tableau des valeurs</summary><table><caption>%s (%s)</caption><tbody>%s</tbody></table></details>',
            self::e($title),
            self::e($unit),
            $rows,
        );
    }

    /**
     * Étiquettes de l'axe : au plus cinq, toujours la première et la dernière.
     *
     * @return list<int>
     */
    private static function labelIndexes(int $count): array
    {
        if ($count <= self::MAX_X_LABELS) {
            return $count === 0 ? [] : range(0, $count - 1);
        }
        $indexes = [];
        for ($k = 0; $k < self::MAX_X_LABELS; ++$k) {
            $indexes[] = (int) round($k * ($count - 1) / (self::MAX_X_LABELS - 1));
        }

        return array_values(array_unique($indexes));
    }

    /** Plus petit « beau » nombre (1, 2, 5 × 10^k) au moins égal au maximum : les repères de l'axe tombent sur des valeurs rondes. */
    private static function niceMax(int|float $max): int|float
    {
        if ($max <= 0) {
            return 0;
        }
        $power = 10 ** floor(log10($max));
        foreach ([1, 2, 5, 10] as $step) {
            if ($max <= $step * $power) {
                return $step * $power;
            }
        }

        return 10 * $power;
    }

    private static function format(int|float $value): string
    {
        $rounded = round((float) $value, 1);

        return number_format($rounded, floor($rounded) === $rounded ? 0 : 1, ',', ' ');
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
