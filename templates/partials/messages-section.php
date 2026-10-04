<?php
/**
 * Messagerie entre groupes (#153) : trois boîtes (Reçues / Envoyées / Archivées) remplies par messages.js,
 * et la modale du fil de conversation. Classes distinctes de celles des demandes de créneau
 * (exception-deck.js ne doit pas piloter ces onglets).
 */
?>
<section class="rb-messages-section" data-messages>
    <h2>Messages</h2>
    <div class="rb-messages-tabs" role="tablist">
        <button type="button" class="rb-messages-tab" role="tab" aria-selected="true" data-messages-box="received">
            Reçues <span class="rb-badge rb-badge-warn" data-messages-unread hidden></span>
        </button>
        <button type="button" class="rb-messages-tab" role="tab" aria-selected="false" data-messages-box="sent">Envoyées</button>
        <button type="button" class="rb-messages-tab" role="tab" aria-selected="false" data-messages-box="archived">Archivées</button>
    </div>
    <ul class="rb-messages-list" data-messages-list></ul>
    <p class="rb-messages-empty" data-messages-empty hidden>Aucune conversation ici. Écrivez à un groupe depuis le planning ou sa page.</p>
</section>

<div class="rb-modal-overlay" data-thread-overlay hidden>
    <div class="rb-modal rb-card rb-thread" role="dialog" aria-modal="true" aria-labelledby="thread-title">
        <h2 id="thread-title" data-thread-title></h2>
        <p class="rb-thread-label" data-thread-label></p>
        <ol class="rb-thread-messages" data-thread-messages></ol>
        <form data-thread-form>
            <div class="rb-field">
                <label for="thread-reply">Votre réponse</label>
                <textarea id="thread-reply" name="message" class="rb-input" rows="3" maxlength="5000" required></textarea>
            </div>
            <div class="rb-modal-actions">
                <button type="button" class="rb-btn" data-thread-archive>Archiver</button>
                <button type="button" class="rb-btn" data-thread-close>Fermer</button>
                <button type="submit" class="rb-btn rb-btn-primary">Envoyer</button>
            </div>
        </form>
    </div>
</div>
