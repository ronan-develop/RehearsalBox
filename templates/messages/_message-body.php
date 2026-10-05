<?php
/**
 * Corps d'une bulle (#200, #214) : citation éventuelle (auteur et début du texte cité, lien vers le message), texte (mentions surlignées, tout contenu d'utilisateur échappé par e()) et heure ; si le texte a été corrigé, « modifié à HH:MM »
 * remplace l'heure d'envoi. Un seul gabarit pour la page et pour le fragment qui remplace un message modifié.
 *
 * @var array<string, mixed> $row ligne de ConversationTimeline::rows()
 */
?>
<?php if (!empty($row['quote'])): ?><a class="rb-chat-quote" href="#message-<?= e((string) $row['quote']['id']) ?>"><span class="rb-chat-quote-author"><?= e($row['quote']['author']) ?></span><span class="rb-chat-quote-text"><?= e($row['quote']['excerpt']) ?></span></a><?php endif; ?>
<p class="rb-chat-text"><?php foreach ($row['segments'] ?? [['text' => $row['body'], 'mention' => false, 'me' => false]] as $segment):
    if ($segment['mention']): ?><span class="rb-chat-mention<?= $segment['me'] ? ' rb-chat-mention--me' : '' ?>"><?= e($segment['text']) ?></span><?php else: ?><?= e($segment['text']) ?><?php endif;
endforeach; ?></p>
<span class="rb-chat-time"><?php if (!empty($row['edited'])): ?><span class="rb-chat-edited">modifié à <?= e($row['edited']) ?></span><?php else: ?><?= e($row['time']) ?><?php endif; ?></span>
