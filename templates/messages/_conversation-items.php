<?php
/**
 * Lignes de la liste des conversations (#183) : de vrais liens (navigation classique), point « non lu », conversation
 * ouverte. Un seul gabarit pour la page et pour le rafraîchissement de la liste (fragment HTML).
 *
 * @var list<array{id: int, url: string, title: string, date: string, preview: string, unread: bool, mentioned?: bool, active: bool}> $items
 */
foreach ($items as $item): ?>
<li class="rb-chat-item<?= $item['unread'] ? ' rb-chat-item--unread' : '' ?><?= $item['active'] ? ' rb-chat-item--active' : '' ?>">
    <a class="rb-chat-item-link" href="<?= e($item['url']) ?>" data-conversation-id="<?= e((string) $item['id']) ?>">
        <span class="rb-chat-item-head">
            <span class="rb-chat-item-title"><?= e($item['title']) ?></span>
            <span class="rb-chat-item-date"><?= e($item['date']) ?></span>
        </span>
        <span class="rb-chat-item-preview"><?= e($item['preview']) ?></span>
        <?php if (!empty($item['mentioned'])): ?><span class="rb-chat-item-mention" aria-label="Vous êtes mentionné(e)">@</span><?php endif; ?>
        <?php if ($item['unread']): ?><span class="rb-chat-item-dot" aria-label="Non lu"></span><?php endif; ?>
    </a>
</li>
<?php endforeach; ?>
