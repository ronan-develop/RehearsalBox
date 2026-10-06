<?php
/**
 * @var string $groupName
 * @var string $when
 * @var string $range
 * @var string $link
 */
?>
Bonjour,

Le groupe <?= $groupName ?> demande à réserver le local :

<?= $when ?>

<?= $range ?>


Vous pouvez valider ou refuser cette réservation depuis l'écran d'administration (connexion requise). Le premier administrateur qui répond tranche :
<?= $link ?>
