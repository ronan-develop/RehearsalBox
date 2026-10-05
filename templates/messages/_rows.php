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
<li class="rb-chat-message<?= $row['mine'] ? ' rb-chat-message--mine' : '' ?>" data-message-id="<?= e((string) $row['id']) ?>">
    <?php if (!$row['mine']): ?>
        <?php if ($row['startsRun']): ?>
            <span class="rb-chat-avatar" aria-hidden="true"<?= $row['color'] !== null ? ' style="--group-color: ' . e($row['color']) . '"' : '' ?><?= $row['groupName'] !== null ? ' title="' . e($row['groupName']) . '"' : '' ?>><?= e($row['initials']) ?></span>
        <?php else: ?>
            <span class="rb-chat-avatar-spacer"></span>
        <?php endif; ?>
    <?php endif; ?>
    <div class="rb-chat-bubble">
        <?php if (!$row['mine'] && $row['startsRun']): ?><span class="rb-chat-author"><?= e($row['author']) ?></span><?php endif; ?>
        <p class="rb-chat-text"><?= e($row['body']) ?></p>
        <span class="rb-chat-time"><?= e($row['time']) ?></span>
    </div>
</li>
<?php endif;
endforeach;
