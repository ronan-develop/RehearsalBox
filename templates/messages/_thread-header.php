<?php
/**
 * En-tête d'une conversation : retour (vrai lien, mobile), puis soit l'intitulé fixe d'un brouillon avec le choix du
 * groupe émetteur, soit le titre (rendu par le serveur) que le composant rend modifiable.
 *
 * @var array{targetId: int, targetName: string, senders: list<array{id: int, name: string}>, blocked: bool}|null $draft
 * @var array<string, mixed>|null $thread
 */
$draft = $draft ?? null;
$thread = $thread ?? null;
$isDraft = $draft !== null;
$severalSenders = $isDraft && count($draft['senders']) > 1;
$hasTitle = $thread !== null && $thread['title'] !== null;
?>
<rb-thread-header>
    <header class="rb-chat-thread-head">
        <a href="/messages" class="rb-chat-icon-link rb-chat-back" data-chat-back aria-label="Retour aux conversations">←</a>
        <div class="rb-chat-thread-titles">
            <h2 class="rb-chat-title rb-chat-title--static" data-chat-draft-part="title"<?= $isDraft ? '' : ' hidden' ?>>Nouvelle conversation<?= $isDraft ? ' avec ' . e($draft['targetName']) : '' ?></h2>
            <label class="rb-chat-sender" for="chat-sender" data-chat-draft-part="sender"<?= $severalSenders ? '' : ' hidden' ?>>Écrire en tant que
                <select id="chat-sender" class="rb-input" data-chat-sender<?= $severalSenders ? '' : ' hidden' ?>>
                    <?php foreach ($isDraft ? $draft['senders'] : [] as $sender): ?>
                        <option value="<?= e((string) $sender['id']) ?>"><?= e($sender['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="button" class="rb-chat-title" data-chat-title data-chat-thread-part data-title="<?= $thread !== null ? e((string) ($thread['title'] ?? '')) : '' ?>" title="Modifier le titre"<?= $isDraft ? ' hidden' : '' ?>><?= $thread !== null ? e($thread['displayTitle']) : '' ?></button>
            <form data-chat-rename-form data-chat-thread-part hidden>
                <input type="text" name="title" class="rb-input" maxlength="150" placeholder="Titre de la conversation" aria-label="Titre de la conversation">
            </form>
            <p class="rb-chat-label" data-chat-label data-chat-thread-part<?= $hasTitle && !$isDraft ? '' : ' hidden' ?>><?= $hasTitle ? e($thread['label']) : '' ?></p>
        </div>
    </header>
</rb-thread-header>
