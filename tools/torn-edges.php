<?php

declare(strict_types=1);

// Génère les variantes de bord « papier déchiré » des cartes du planning (#268) : php tools/torn-edges.php
// Sortie : le bloc CSS à coller dans public/assets/css/pages/dashboard.css (dans le bloc ordinateur, `@media (min-width: 768px)`).
// Un polygone par variante : 4 côtés de 20 points, décalés vers l'INTÉRIEUR de la carte (jamais au-delà de la boîte) d'un bruit lissé
// plus un peu de grain, avec quelques entailles plus profondes ; l'amplitude est du même ordre que l'ancien filtre (~4 px).
// Déterministe (graine fixée) : relancer donne le même CSS.

const VARIANTS = 6;
const POINTS_PER_SIDE = 20;
// Amplitude maximale en % : environ 4 px pour une carte de 176 × 132 px (haut/bas : % de la hauteur, côtés : % de la largeur).
const MAX_HORIZONTAL_EDGE = 3.0;
const MAX_VERTICAL_EDGE = 2.3;

/** @return list<float> POINTS_PER_SIDE + 1 décalages positifs (le dernier ferme le côté suivant) */
function edge(float $max): array
{
    $knots = [];
    for ($k = 0; $k <= 5; ++$k) {
        $knots[] = mt_rand(0, 1000) / 1000;
    }
    $offsets = [];
    for ($i = 0; $i <= POINTS_PER_SIDE; ++$i) {
        $position = $i / POINTS_PER_SIDE * 5;
        $left = (int) floor($position);
        $right = min(5, $left + 1);
        $fraction = $position - $left;
        $smooth = $knots[$left] * (1 - $fraction) + $knots[$right] * $fraction;
        $grain = mt_rand(0, 1000) / 1000;
        $nick = mt_rand(0, 100) < 8 ? 1.0 : 0.0;
        $value = min(1.0, 0.55 * $smooth + 0.5 * $grain + 0.3 * $nick);
        $offsets[] = round($value * $max, 1);
    }

    return $offsets;
}

mt_srand(268);
echo "  /* Bord « papier déchiré » (#268) : 6 découpes pré-calculées, générées par tools/torn-edges.php (php tools/torn-edges.php). Aucun\n";
echo "     filtre SVG ni JavaScript : le bord est là dès le premier affichage. À régénérer, jamais à retoucher à la main. */\n";
for ($variant = 1; $variant <= VARIANTS; ++$variant) {
    $top = edge(MAX_HORIZONTAL_EDGE);
    $right = edge(MAX_VERTICAL_EDGE);
    $bottom = edge(MAX_HORIZONTAL_EDGE);
    $left = edge(MAX_VERTICAL_EDGE);
    $points = [];
    for ($i = 0; $i < POINTS_PER_SIDE; ++$i) {
        $points[] = sprintf('%.1f%% %.1f%%', $i / POINTS_PER_SIDE * 100, $top[$i]);
    }
    for ($i = 0; $i < POINTS_PER_SIDE; ++$i) {
        $points[] = sprintf('%.1f%% %.1f%%', 100 - $right[$i], $i / POINTS_PER_SIDE * 100);
    }
    for ($i = 0; $i < POINTS_PER_SIDE; ++$i) {
        $points[] = sprintf('%.1f%% %.1f%%', 100 - $i / POINTS_PER_SIDE * 100, 100 - $bottom[$i]);
    }
    for ($i = 0; $i < POINTS_PER_SIDE; ++$i) {
        $points[] = sprintf('%.1f%% %.1f%%', $left[$i], 100 - $i / POINTS_PER_SIDE * 100);
    }
    echo sprintf("  .rb-planning-card:nth-child(6n + %d) {\n    clip-path: polygon(\n", $variant);
    echo implode(",\n", array_map(static fn (array $chunk): string => '      ' . implode(', ', $chunk), array_chunk($points, 8))) . "\n";
    echo "    );\n  }\n";
}
