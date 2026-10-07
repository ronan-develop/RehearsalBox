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
            <?php $avatarInitials = $row['initials']; $avatarClass = 'rb-avatar--sm rb-chat-avatar'; $avatarColor = $row['color']; $avatarTitle = $row['groupName']; require __DIR__ . '/../partials/avatar.php'; ?>
        <?php else: ?>
            <span class="rb-chat-avatar-spacer"></span>
        <?php endif; ?>
    <?php endif; ?>
    <div class="rb-chat-bubble">
        <?php if (!$row['mine'] && $row['startsRun']): ?><span class="rb-chat-author"><?= e($row['author']) ?></span><?php endif; ?>
        <div class="rb-chat-body" data-message-body><?php require __DIR__ . '/_message-body.php'; ?></div>
    </div>
    <?php /* Citer et corriger : au survol ou au clavier sur ordinateur ; sur écran tactile, le composant cloné au tap (<rb-message-actions>, #257). */ ?>
    <?php $withEdit = !empty($row['editable']); require __DIR__ . '/_message-action-buttons.php'; ?>
</li>
<?php endif;
endforeach;
