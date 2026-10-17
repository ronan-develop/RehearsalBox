<?php
/**
 * @var int $count
 * @var int $days
 * @var string $link
 */
?>
Bonjour,

La comptabilité de l'association a <?= $count ?> tâche<?= $count > 1 ? 's' : '' ?> en retard, dont la plus ancienne depuis plus de <?= $days ?> jours. Le trésorier a déjà été prévenu.

Vous pouvez consulter la liste « À faire » (connexion requise) :
<?= $link ?>


Ce message part au plus une fois par semaine et s'arrête dès que les tâches sont faites. Aucun montant ni aucun nom n'est envoyé par e-mail.
