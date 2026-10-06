<?php
/**
 * Boutons d'action d'un message : citer (#214) et, s'il est encore modifiable, corriger (#200). UNE seule définition, deux
 * usages : dans chaque ligne du fil (survol ou clavier sur ordinateur) et dans le modèle <template data-message-actions> que
 * tap-actions.js clone au tap sur écran tactile (#257).
 *
 * @var bool $withEdit afficher aussi le crayon
 */
?>
<button type="button" class="rb-chat-quote-action" data-quote-message aria-label="Répondre à ce message" title="Répondre à ce message">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5V20"/></svg>
</button>
<?php if ($withEdit): ?>
<button type="button" class="rb-chat-edit" data-edit-message aria-label="Modifier ce message" title="Modifier ce message">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
</button>
<?php endif; ?>
