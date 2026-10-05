<?php
/**
 * Composant <rb-composer> : champ + envoi. Même composant pour une conversation existante et pour un brouillon (la page
 * de démarrage) ; masqué quand on ne peut pas écrire.
 *
 * @var bool $composerHidden
 */
$composerHidden = $composerHidden ?? false;
?>
<rb-composer>
    <form class="rb-chat-form" data-chat-form<?= $composerHidden ? ' hidden' : '' ?>>
        <ul class="rb-chat-mention-list" role="listbox" aria-label="Personnes à mentionner" data-mention-list hidden></ul>
        <p class="rb-chat-mention-notice" role="status" data-mention-notice hidden></p>
        <textarea name="message" rows="1" maxlength="5000" class="rb-input" placeholder="Votre message (@ pour mentionner)" aria-label="Votre message" aria-autocomplete="list" required></textarea>
        <button type="submit" class="rb-btn rb-btn-primary rb-chat-send">Envoyer</button>
    </form>
</rb-composer>
