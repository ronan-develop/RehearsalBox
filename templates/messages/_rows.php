<?php
/**
 * Lignes du fil (#183) : séparateurs de jour, « Messages non lus », lignes système et bulles. Un seul gabarit pour le
 * premier affichage de la page ET pour les mises à jour (fragments HTML) : tout contenu d'utilisateur passe par e().
 *
 * @var list<array<string, mixed>> $rows lignes de ConversationTimeline::rows()
 */
foreach ($rows as $row):
    if ($row['type'] === 'day'): ?>
<li class="rb-chat-day"><?= e($row['label']) ?></li>
<?php elseif ($row['type'] === 'unread'): ?>
<li class="rb-chat-unread">Messages non lus</li>
<?php elseif ($row['type'] === 'system'): ?>
<li class="rb-chat-system"><?= e($row['text']) ?></li>
<?php else: ?>
<li id="message-<?= e((string) $row['id']) ?>" class="rb-chat-message<?= $row['mine'] ? ' rb-chat-message--mine' : '' ?><?= !empty($row['mentionsMe']) ? ' rb-chat-message--mentioned' : '' ?>" data-message-id="<?= e((string) $row['id']) ?>" data-author="<?= e($row['author']) ?>"<?= !empty($row['editable']) ? ' data-editable' : '' ?>>
    <?php if (!$row['mine']): ?>
        <?php if ($row['startsRun']): ?>
            <span class="rb-chat-avatar" aria-hidden="true"<?= $row['color'] !== null ? ' style="--group-color: ' . e($row['color']) . '"' : '' ?><?= $row['groupName'] !== null ? ' title="' . e($row['groupName']) . '"' : '' ?>><?= e($row['initials']) ?></span>
        <?php else: ?>
            <span class="rb-chat-avatar-spacer"></span>
        <?php endif; ?>
    <?php endif; ?>
    <div class="rb-chat-bubble">
        <?php if (!$row['mine'] && $row['startsRun']): ?><span class="rb-chat-author"><?= e($row['author']) ?></span><?php endif; ?>
        <div class="rb-chat-body" data-message-body><?php require __DIR__ . '/_message-body.php'; ?></div>
    </div>
    <?php /* Citer (#214) : au survol ou au clavier sur ordinateur ; sur mobile, tap sur la bulle (tap-actions.js, #253). */ ?>
    <button type="button" class="rb-chat-quote-action" data-quote-message aria-label="Répondre à ce message" title="Répondre à ce message">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5V20"/></svg>
    </button>
    <?php if (!empty($row['editable'])): ?>
        <?php /* Corriger : au survol ou au clavier sur ordinateur ; sur mobile, tap sur la bulle (tap-actions.js, #253). */ ?>
        <button type="button" class="rb-chat-edit" data-edit-message aria-label="Modifier ce message" title="Modifier ce message">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
        </button>
    <?php endif; ?>
</li>
<?php endif;
endforeach;
