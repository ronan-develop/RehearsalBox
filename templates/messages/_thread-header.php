<?php
/**
 * Composant « en-tête de conversation » : retour (mobile), titre modifiable ou intitulé de brouillon, label des groupes,
 * choix du groupe émetteur (brouillon avec plusieurs groupes).
 *
 * @var array{targetId: int, targetName: string, senders: list<array{id: int, name: string}>, blocked: bool}|null $draft
 */
$draft = $draft ?? null;
?>
<header class="rb-chat-thread-head">
    <a href="/messages" class="rb-chat-icon-link rb-chat-back" data-chat-back aria-label="Retour aux conversations">←</a>
    <div class="rb-chat-thread-titles">
        <?php if ($draft !== null): ?>
            <h2 class="rb-chat-title rb-chat-title--static" data-chat-title>Nouvelle conversation avec <?= e($draft['targetName']) ?></h2>
            <label class="rb-chat-sender" for="chat-sender"<?= count($draft['senders']) > 1 ? '' : ' hidden' ?>>Écrire en tant que
                <select id="chat-sender" class="rb-input" data-chat-sender<?= count($draft['senders']) > 1 ? '' : ' hidden' ?>>
                    <?php foreach ($draft['senders'] as $sender): ?>
                        <option value="<?= e((string) $sender['id']) ?>"><?= e($sender['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php else: ?>
            <button type="button" class="rb-chat-title" data-chat-title title="Modifier le titre"></button>
            <form data-chat-rename-form hidden>
                <input type="text" name="title" class="rb-input" maxlength="150" placeholder="Titre de la conversation" aria-label="Titre de la conversation">
            </form>
            <p class="rb-chat-label" data-chat-label></p>
        <?php endif; ?>
    </div>
</header>
