<?php
/**
 * Colonne de gauche (#183) : liste des conversations rendue par le serveur, de vrais liens, et l'entrée « Archivées »
 * (une vraie page, /messages/archives). Sans JS tout fonctionne ; <rb-sidebar> ne fait que rafraîchir la liste en direct.
 *
 * @var array{items: list<array<string, mixed>>, box: string, archivedUnread: int, alerts: list<array{id: int, text: string, url: ?string}>, trashCount: int} $sidebar
 */
$archived = $sidebar['box'] === 'archived';
$items = $sidebar['items'];
?>
<rb-sidebar data-box="<?= $archived ? 'archived' : 'active' ?>">
    <aside class="rb-chat-sidebar" aria-label="Conversations">
        <header class="rb-chat-sidebar-head">
            <a href="<?= $archived ? '/messages' : '/' ?>" class="rb-back-link" data-chat-home aria-label="<?= $archived ? 'Retour aux conversations' : 'Retour aux disponibilités' ?>"><?php require __DIR__ . '/../partials/icon-back.php'; ?></a>
            <h1 data-chat-list-title><?= $archived ? 'Archivées' : 'Messages' ?></h1>
            <a href="/messages/direct" class="rb-btn rb-chat-new-dm" data-chat-new-dm>Nouveau message</a>
        </header>
        <?php foreach ($sidebar['alerts'] as $alert): ?>
            <div class="rb-chat-alert" role="status" data-trash-alert>
                <p><?php if ($alert['url'] !== null): ?><a href="<?= e($alert['url']) ?>"><?= e($alert['text']) ?></a><?php else: ?><?= e($alert['text']) ?><?php endif; ?></p>
                <button type="button" class="rb-chat-alert-close" data-trash-action="dismiss" data-id="<?= e((string) $alert['id']) ?>" aria-label="Fermer l'avis">×</button>
            </div>
        <?php endforeach; ?>
        <?php if (!$archived): ?>
            <a href="/messages/archives" class="rb-chat-archives" data-chat-archives>
                Archivées <span class="rb-badge rb-badge-warn" data-chat-archives-unread<?= $sidebar['archivedUnread'] > 0 ? '' : ' hidden' ?>><?= e((string) $sidebar['archivedUnread']) ?></span>
            </a>
        <?php endif; ?>
        <?php if ($sidebar['trashCount'] > 0): ?>
            <a href="/messages/trash" class="rb-chat-archives">Corbeille <span class="rb-badge" aria-label="<?= e((string) $sidebar['trashCount']) ?> conversation(s)"><?= e((string) $sidebar['trashCount']) ?></span></a>
        <?php endif; ?>
        <ul class="rb-chat-list" data-chat-list><?php require __DIR__ . '/_conversation-items.php'; ?></ul>
        <p class="rb-chat-empty" data-chat-empty<?= $items === [] ? '' : ' hidden' ?>><?= $archived ? 'Aucune conversation archivée.' : 'Aucune conversation. Écrivez à un groupe depuis le planning, ou à un membre avec « Nouveau message ».' ?></p>
    </aside>
</rb-sidebar>
