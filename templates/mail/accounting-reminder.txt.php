<?php
/**
 * @var int $count
 * @var string $link
 */
?>
Bonjour,

Il reste <?= $count ?> tâche<?= $count > 1 ? 's' : '' ?> de comptabilité en retard : relevés à déposer, mouvements à saisir ou exercice à clôturer.

Ouvrez la comptabilité (connexion requise) : la liste « À faire » vous dit quoi faire, dans l'ordre :
<?= $link ?>


Ce message part au plus une fois par semaine et s'arrête dès que les tâches sont faites. Aucun montant ni aucun nom n'est envoyé par e-mail.
