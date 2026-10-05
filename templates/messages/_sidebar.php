<?php
/**
 * Composant « liste des conversations » (colonne de gauche). Rempli par chat.js ; aucun contenu de message ici.
 * Réutilisable par toute page de messagerie (fil, nouvelle conversation, futures vues).
 */
?>
<aside class="rb-chat-sidebar" aria-label="Conversations">
    <header class="rb-chat-sidebar-head">
        <a href="/" class="rb-chat-icon-link" data-chat-home aria-label="Retour aux disponibilités">←</a>
        <button type="button" class="rb-chat-icon-link" data-chat-leave-archives aria-label="Retour aux conversations" hidden>←</button>
        <h1 data-chat-list-title>Messages</h1>
    </header>
    <button type="button" class="rb-chat-archives" data-chat-archives>
        Archivées <span class="rb-badge rb-badge-warn" data-chat-archives-unread hidden></span>
    </button>
    <ul class="rb-chat-list" data-chat-list></ul>
    <p class="rb-chat-empty" data-chat-empty hidden>Aucune conversation. Écrivez à un groupe depuis le planning ou depuis sa page.</p>
</aside>
