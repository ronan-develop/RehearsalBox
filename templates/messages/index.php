<?php
/**
 * Messagerie (#169), inspirée de Signal : liste des conversations à gauche, fil à droite sur grand écran ;
 * sur mobile, la liste OU le fil en plein écran (data-view). Rempli par chat.js ; aucun contenu de message ici.
 *
 * @var string   $csrfToken
 * @var int|null $activeId conversation ouverte (route /messages/{id}), null sur /messages
 */
$activeId = $activeId ?? null;
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <title>Messages — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/messages.css">
</head>
<body class="rb-chat-body">
    <div class="rb-chat" data-chat data-active-id="<?= e($activeId === null ? '' : (string) $activeId) ?>" data-view="<?= $activeId === null ? 'list' : 'thread' ?>">
        <aside class="rb-chat-sidebar" aria-label="Conversations">
            <header class="rb-chat-sidebar-head">
                <a href="/" class="rb-chat-icon-link" data-chat-home aria-label="Retour aux disponibilités">←</a>
                <button type="button" class="rb-chat-icon-link" data-chat-leave-archives aria-label="Retour aux conversations" hidden>←</button>
                <h1 data-chat-list-title>Messages</h1>
            </header>
            <button type="button" class="rb-chat-archives" data-chat-archives>
                Archivées <span class="rb-badge rb-badge-warn" data-chat-archives-unread hidden></span>
            </button>
            <ul class="rb-chat-list" data-chat-list></ul>
            <p class="rb-chat-empty" data-chat-empty hidden>Aucune conversation. Écrivez à un groupe depuis le planning ou depuis sa page.</p>
        </aside>

        <section class="rb-chat-main" aria-label="Conversation">
            <div class="rb-chat-placeholder" data-chat-placeholder<?= $activeId === null ? '' : ' hidden' ?>>
                <p>Choisissez une conversation.</p>
            </div>
            <div class="rb-chat-thread" data-chat-thread<?= $activeId === null ? ' hidden' : '' ?>>
                <header class="rb-chat-thread-head">
                    <a href="/messages" class="rb-chat-icon-link rb-chat-back" data-chat-back aria-label="Retour aux conversations">←</a>
                    <div class="rb-chat-thread-titles">
                        <button type="button" class="rb-chat-title" data-chat-title title="Modifier le titre"></button>
                        <form data-chat-rename-form hidden>
                            <input type="text" name="title" class="rb-input" maxlength="150" placeholder="Titre de la conversation" aria-label="Titre de la conversation">
                        </form>
                        <p class="rb-chat-label" data-chat-label></p>
                    </div>
                </header>
                <ol class="rb-chat-messages" data-chat-messages aria-live="polite"></ol>
                <p class="rb-chat-status" data-chat-status aria-live="polite"></p>
                <form class="rb-chat-form" data-chat-form>
                    <textarea name="message" rows="1" maxlength="5000" class="rb-input" placeholder="Votre message" aria-label="Votre message" required></textarea>
                    <button type="submit" class="rb-btn rb-btn-primary rb-chat-send">Envoyer</button>
                </form>
            </div>
        </section>
    </div>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
