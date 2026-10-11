<?php
/**
 * @var list<string> $messages
 * @var list<array{label: string, url: string}> $links
 */
?>
Alerte de mesures

Un seuil critique a été franchi :

<?php foreach ($messages as $message): ?>
- <?= $message ?>

<?php endforeach; ?>

<?php foreach ($links as ['label' => $label, 'url' => $url]): ?>
<?= $label ?> : <?= $url ?>
<?php endforeach; ?>

Le lien vous demande de vous connecter avec le compte propriétaire, puis vous amène sur la bonne page.


Une alerte de même type n'est pas renvoyée avant plusieurs heures.
Ce message ne contient aucune donnée personnelle.
