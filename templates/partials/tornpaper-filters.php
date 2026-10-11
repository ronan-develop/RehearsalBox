<?php
/**
 * Filtres « papier déchiré » des cartes du planning (#268) : le bord déchiré du filtre TornPaper (bord adouci, érodé puis déplacé par un bruit,
 * mêmes réglages d'origine), déclaré par le serveur dans le HTML. Le navigateur le connaît donc dès le premier affichage : la feuille de
 * style l'applique aux cartes (`:nth-child(6n + k)`, dashboard.css), sans attendre le JavaScript, sans repli anguleux ni saut. Six
 * variantes (six graines fixes) pour que les cartes soient variées. Le RELIEF du papier (éclairage du bruit), la partie coûteuse du filtre
 * d'origine, n'est plus calculé par le navigateur : c'est une image tuilable générée une fois par `tools/paper-grain.mjs` (même filtre).
 * Le SVG ne s'affiche pas (taille nulle) ; ce n'est ni `display: none` ni un style en ligne (politique de sécurité), voir `.rb-tornpaper-defs`.
 */
$tornpaperSeeds = [1873204, 5520917, 903611, 7241185, 3318472, 6089963];
?>
<svg class="rb-tornpaper-defs" width="0" height="0" aria-hidden="true" focusable="false">
<?php foreach ($tornpaperSeeds as $index => $seed): ?>
    <filter id="tornpaper-<?= $index + 1 ?>"><feGaussianBlur in="SourceAlpha" stdDeviation="0.5" /><feMorphology operator="erode" radius="3" /><feOffset dx="-1" dy="-1" result="inset" /><feTurbulence baseFrequency="0.045" type="turbulence" numOctaves="3" seed="<?= $seed ?>" result="edge_noise" /><feDisplacementMap in="inset" in2="edge_noise" scale="9" xChannelSelector="B" yChannelSelector="G" result="edge" /><feComposite in="SourceGraphic" in2="edge" operator="in" /></filter>
<?php endforeach; ?>
</svg>
