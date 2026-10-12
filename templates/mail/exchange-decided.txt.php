<?php
/**
 * @var string $requesterGroup
 * @var string $holderGroup
 * @var string $when
 * @var string $range
 * @var bool   $accepted
 * @var string $link
 */
?>
Bonjour,

Le groupe <?= $holderGroup ?> a <?= $accepted ? 'accepté' : 'refusé' ?> la demande de <?= $requesterGroup ?> :
<?= $when ?>, <?= $range ?>


Ouvrir le planning :

<?= $link ?>
