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
        <?php if ($thread !== null && $thread['canDelete'] && !$isDraft): ?>
            <button type="button" class="rb-btn rb-btn-danger rb-chat-delete" data-trash-action="delete" data-id="<?= e((string) $thread['id']) ?>" data-chat-thread-part aria-label="Supprimer la conversation">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 11v6M14 11v6"/></svg>
                <span class="rb-chat-delete-label">Supprimer</span>
            </button>
        <?php endif; ?>
    </header>
</rb-thread-header>
