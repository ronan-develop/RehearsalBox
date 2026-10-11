<?php
/**
 * @var list<string> $messages
 * @var list<string> $links
 */
?>
Alerte de mesures

Un seuil critique a été franchi :

<?php foreach ($messages as $message): ?>
- <?= $message ?>

<?php endforeach; ?>

<?php foreach ($links as $link): ?>
Page concernée : <?= $link ?>
<?php endforeach; ?>


Une alerte de même type n'est pas renvoyée avant plusieurs heures.
Ce message ne contient aucune donnée personnelle.
