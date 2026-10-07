<?php
/**
 * Pastille d'initiales (#324) : cercle décoratif aux couleurs du groupe (ou, sans couleur, du ton neutre). Un seul gabarit pour les
 * cartes de demande, l'en-tête du tableau de bord et la messagerie ; la taille et le fond se règlent par variables CSS.
 *
 * @var string      $avatarInitials les initiales (échappées ici)
 * @var string      $avatarClass    classes en plus de rb-avatar (taille, contexte)
 * @var string|null $avatarColor    couleur de groupe validée « #rrggbb », ou null
 * @var string|null $avatarTitle    infobulle (nom du groupe), ou null
 */
?>
<span class="rb-avatar<?= $avatarClass !== '' ? ' ' . e($avatarClass) : '' ?>" aria-hidden="true"<?= $avatarColor !== null ? ' style="--group-color: ' . e($avatarColor) . '"' : '' ?><?= $avatarTitle !== null ? ' title="' . e($avatarTitle) . '"' : '' ?>><?= e($avatarInitials) ?></span>
