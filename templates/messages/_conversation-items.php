<?php
/**
 * Lignes de la liste des conversations (#183) : de vrais liens (navigation classique), point « non lu », conversation
 * ouverte. Un seul gabarit pour la page et pour le rafraîchissement de la liste (fragment HTML).
 *
 * @var list<array{id: int, url: string, title: string, date: string, preview: string, unread: bool, mentioned?: bool, canDelete?: bool, active: bool}> $items
 */
foreach ($items as $item): ?>
<li class="rb-chat-item<?= $item['unread'] ? ' rb-chat-item--unread' : '' ?><?= $item['active'] ? ' rb-chat-item--active' : '' ?>"<?= !empty($item['canDelete']) ? ' data-can-delete' : '' ?>>
    <a class="rb-chat-item-link" href="<?= e($item['url']) ?>" data-conversation-id="<?= e((string) $item['id']) ?>">
        <span class="rb-chat-item-head">
            <span class="rb-chat-item-title"><?= e($item['title']) ?></span>
            <span class="rb-chat-item-date"><?= e($item['date']) ?></span>
        </span>
        <span class="rb-chat-item-preview"><?= e($item['preview']) ?></span>
        <?php if (!empty($item['mentioned'])): ?><span class="rb-chat-item-mention" aria-label="Vous êtes mentionné(e)">@</span><?php endif; ?>
        <?php if ($item['unread']): ?><span class="rb-chat-item-dot" aria-label="Non lu"></span><?php endif; ?>
    </a>
    <?php if (!empty($item['canDelete'])): ?>
        <?php /* Supprimer : colonne à droite sur ordinateur, révélée par un glissement vers la gauche sur mobile. Hors du lien. */ ?>
        <button type="button" class="rb-chat-item-delete" data-trash-action="delete" data-id="<?= e((string) $item['id']) ?>" aria-label="Supprimer la conversation <?= e($item['title']) ?>">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 11v6M14 11v6"/></svg>
        </button>
    <?php endif; ?>
</li>
<?php endforeach; ?>
