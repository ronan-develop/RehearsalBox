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
        <div class="rb-chat-editing" role="status" data-composer-edit hidden>
            <span>Modification du message</span>
            <button type="button" class="rb-chat-editing-cancel" data-composer-edit-cancel>Annuler</button>
        </div>
        <div class="rb-chat-quoting" role="status" data-composer-quote hidden>
            <div class="rb-chat-quoting-text">
                <span class="rb-chat-quoting-author" data-composer-quote-author></span>
                <span class="rb-chat-quoting-excerpt" data-composer-quote-text></span>
            </div>
            <button type="button" class="rb-chat-quoting-cancel" data-composer-quote-cancel aria-label="Ne plus citer ce message">×</button>
        </div>
        <ul class="rb-chat-mention-list" role="listbox" aria-label="Personnes à mentionner" data-mention-list hidden></ul>
        <p class="rb-chat-mention-notice" role="status" data-mention-notice hidden></p>
        <textarea name="message" rows="1" maxlength="5000" class="rb-input" placeholder="Votre message (@ pour mentionner)" aria-label="Votre message" aria-autocomplete="list" enterkeyhint="send" required></textarea>
        <button type="submit" class="rb-btn rb-btn-primary rb-chat-send">Envoyer</button>
    </form>
</rb-composer>
