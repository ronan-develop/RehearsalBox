<?php
/**
 * Bouton « page précédente » des pages de l'application (#404) : un lien .rb-back-link avec l'icône commune (partials/icon-back.php).
 * La destination est toujours la page parente, jamais l'historique du navigateur (la page peut être ouverte depuis un e-mail).
 *
 * @var string $backHref  adresse de la page parente (chemin du site)
 * @var string $backLabel texte lu par les lecteurs d'écran (« Retour à … »)
 */
?>
<a href="<?= e($backHref) ?>" class="rb-back-link" aria-label="<?= e($backLabel) ?>"><?php require __DIR__ . '/icon-back.php'; ?></a>
