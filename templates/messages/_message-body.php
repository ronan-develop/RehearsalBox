<?php
/**
 * Corps d'une bulle (#200) : texte (mentions surlignées, tout contenu d'utilisateur échappé par e()) et heure ; si le texte a été corrigé, « modifié à HH:MM »
 * remplace l'heure d'envoi. Un seul gabarit pour la page et pour le fragment qui remplace un message modifié.
 *
 * @var array<string, mixed> $row ligne de ConversationTimeline::rows()
 */
?>
<p class="rb-chat-text"><?php foreach ($row['segments'] ?? [['text' => $row['body'], 'mention' => false, 'me' => false]] as $segment):
    if ($segment['mention']): ?><span class="rb-chat-mention<?= $segment['me'] ? ' rb-chat-mention--me' : '' ?>"><?= e($segment['text']) ?></span><?php else: ?><?= e($segment['text']) ?><?php endif;
endforeach; ?></p>
<span class="rb-chat-time"><?php if (!empty($row['edited'])): ?><span class="rb-chat-edited">modifié à <?= e($row['edited']) ?></span><?php else: ?><?= e($row['time']) ?><?php endif; ?></span>
