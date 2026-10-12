<?php
/**
 * @var string $requesterGroup
 * @var string $holderGroup
 * @var string $when
 * @var string $range
 * @var string $link
 */
?>
Bonjour,

Le groupe <?= $requesterGroup ?> demande à échanger le créneau de <?= $holderGroup ?> :
<?= $when ?>, <?= $range ?>


Connectez-vous à l'appli : la demande est dans « Demandes de créneau », à l'onglet « Reçues ». Vous pouvez l'accepter ou la refuser :

<?= $link ?>
