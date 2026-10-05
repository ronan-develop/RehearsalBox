<?php
/**
 * Composant « zone de saisie » : champ + envoi. Même composant pour une conversation existante et pour un brouillon
 * (la page de démarrage) ; masqué quand on ne peut pas écrire.
 *
 * @var bool $composerHidden
 */
$composerHidden = $composerHidden ?? false;
?>
<form class="rb-chat-form" data-chat-form<?= $composerHidden ? ' hidden' : '' ?>>
    <textarea name="message" rows="1" maxlength="5000" class="rb-input" placeholder="Votre message" aria-label="Votre message" required></textarea>
    <button type="submit" class="rb-btn rb-btn-primary rb-chat-send">Envoyer</button>
</form>
