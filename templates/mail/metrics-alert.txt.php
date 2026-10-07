<?php
/**
 * @var list<string> $messages
 * @var string $link
 */
?>
Alerte de mesures

Un seuil critique a été franchi :

<?php foreach ($messages as $message): ?>
- <?= $message ?>

<?php endforeach; ?>

Tableau de bord : <?= $link ?>


Une alerte de même type n'est pas renvoyée avant plusieurs heures.
Ce message ne contient aucune donnée personnelle.
