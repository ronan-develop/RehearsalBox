<?php
/**
 * Messagerie (#169), inspirée de Signal : liste des conversations à gauche, fil à droite sur grand écran ;
 * sur mobile, la liste OU le fil en plein écran (data-view). Rempli par chat.js ; aucun contenu de message ici.
 *
 * @var string   $csrfToken
 * @var int|null $activeId conversation ouverte (route /messages/{id}), null sur /messages
 * @var array{targetId: int, targetName: string, senders: list<array{id: int, name: string}>, blocked: bool}|null $draft
 *            brouillon de la page /messages/new/{groupId} (aucune conversation n'existe avant le premier message)
 */
$activeId = $activeId ?? null;
$draft = $draft ?? null;
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
    <div class="rb-chat" data-chat data-active-id="<?= e($activeId === null ? '' : (string) $activeId) ?>"<?= $draft !== null ? ' data-draft-target-id="' . e((string) $draft['targetId']) . '"' : '' ?><?= $draft !== null && $draft['blocked'] ? ' data-draft-blocked' : '' ?> data-view="<?= $activeId === null && $draft === null ? 'list' : 'thread' ?>">
        <?php require __DIR__ . '/_sidebar.php'; ?>

        <section class="rb-chat-main" aria-label="Conversation">
            <div class="rb-chat-placeholder" data-chat-placeholder<?= $activeId === null && $draft === null ? '' : ' hidden' ?>>
                <p>Choisissez une conversation.</p>
            </div>
            <div class="rb-chat-thread" data-chat-thread<?= $activeId === null && $draft === null ? ' hidden' : '' ?>>
                <?php require __DIR__ . '/_thread-header.php'; ?>
                <ol class="rb-chat-messages" data-chat-messages aria-live="polite"></ol>
                <?php if ($draft !== null && $draft['blocked']): ?>
                    <p class="rb-chat-notice" role="alert">Vous devez appartenir à un autre groupe pour écrire à celui-ci.</p>
                <?php endif; ?>
                <p class="rb-chat-status" data-chat-status aria-live="polite"></p>
                <?php $composerHidden = $draft !== null && $draft['blocked']; require __DIR__ . '/_composer.php'; ?>
            </div>
        </section>
    </div>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
