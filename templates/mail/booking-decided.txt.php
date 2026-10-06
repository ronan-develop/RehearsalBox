<?php
/**
 * @var string      $groupName
 * @var string      $when
 * @var string      $range
 * @var bool        $accepted
 * @var string|null $note
 * @var string      $link
 */
?>
Bonjour,

La réservation du local pour le groupe <?= $groupName ?> a été <?= $accepted ? 'validée' : 'refusée' ?> :

<?= $when ?>

<?= $range ?>

<?php if (!$accepted && $note !== null && $note !== ''): ?>

Motif : <?= $note ?>

<?php endif; ?>

Ouvrir le planning (connexion requise) :
<?= $link ?>
