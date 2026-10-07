<?php
/**
 * État vide d'une liste (#322) : icône de calendrier et phrase, masqué par l'attribut hidden tant que la liste a des éléments.
 *
 * @var string $emptyText   la phrase affichée
 * @var bool   $emptyHidden vrai quand la liste n'est pas vide (le bloc existe quand même : le JavaScript l'affiche au dernier retrait)
 */
?>
<div class="rb-exception-empty"<?= $emptyHidden ? ' hidden' : '' ?>>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>
    <p><?= e($emptyText) ?></p>
</div>
