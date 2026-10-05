<?php
/**
 * Colonne de gauche (#183) : liste des conversations rendue par le serveur, de vrais liens, et l'entrée « Archivées »
 * (une vraie page, /messages/archives). Sans JS tout fonctionne ; <rb-sidebar> ne fait que rafraîchir la liste en direct.
 *
 * @var array{items: list<array<string, mixed>>, box: string, archivedUnread: int} $sidebar
 */
$archived = $sidebar['box'] === 'archived';
$items = $sidebar['items'];
?>
<rb-sidebar data-box="<?= $archived ? 'archived' : 'active' ?>">
    <aside class="rb-chat-sidebar" aria-label="Conversations">
        <header class="rb-chat-sidebar-head">
            <a href="<?= $archived ? '/messages' : '/' ?>" class="rb-chat-icon-link" data-chat-home aria-label="<?= $archived ? 'Retour aux conversations' : 'Retour aux disponibilités' ?>">←</a>
            <h1 data-chat-list-title><?= $archived ? 'Archivées' : 'Messages' ?></h1>
        </header>
        <?php if (!$archived): ?>
            <a href="/messages/archives" class="rb-chat-archives" data-chat-archives>
                Archivées <span class="rb-badge rb-badge-warn" data-chat-archives-unread<?= $sidebar['archivedUnread'] > 0 ? '' : ' hidden' ?>><?= e((string) $sidebar['archivedUnread']) ?></span>
            </a>
        <?php endif; ?>
        <ul class="rb-chat-list" data-chat-list><?php require __DIR__ . '/_conversation-items.php'; ?></ul>
        <p class="rb-chat-empty" data-chat-empty<?= $items === [] ? '' : ' hidden' ?>><?= $archived ? 'Aucune conversation archivée.' : 'Aucune conversation. Écrivez à un groupe depuis le planning ou depuis sa page.' ?></p>
    </aside>
</rb-sidebar>
