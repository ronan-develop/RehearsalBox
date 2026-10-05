<?php
/**
 * Messagerie (#169), inspirée de Signal : liste des conversations à gauche, fil à droite sur grand écran ;
 * sur mobile, la liste OU le fil en plein écran (data-view). Rempli par chat.js ; aucun contenu de message ici.
 *
 * @var string   $csrfToken
 * @var int      $currentUserId clé des brouillons conservés dans le navigateur (#187) : un par utilisateur
 * @var array{items: list<array<string, mixed>>, box: string, archivedUnread: int} $sidebar liste (rendue par le serveur)
 * @var array<string, mixed>|null $thread conversation ouverte (route /messages/{id}), null ailleurs
 * @var array{targetId: int, targetName: string, senders: list<array{id: int, name: string}>, blocked: bool}|null $draft
 *            brouillon de la page /messages/new/{groupId} (aucune conversation n'existe avant le premier message)
 */
$thread = $thread ?? null;
$draft = $draft ?? null;
$hasPane = $thread !== null || $draft !== null;
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
    <rb-chat class="rb-chat" data-chat data-user-id="<?= e((string) ($currentUserId ?? 0)) ?>" data-active-id="<?= $thread !== null ? e((string) $thread['id']) : '' ?>" data-last-id="<?= $thread !== null ? e((string) $thread['lastId']) : '0' ?>" data-edited-at="<?= $thread !== null ? e((string) $thread['editedAt']) : '0' ?>"<?= $draft !== null ? ' data-draft-target-id="' . e((string) $draft['targetId']) . '"' : '' ?><?= $draft !== null && $draft['blocked'] ? ' data-draft-blocked' : '' ?> data-view="<?= $hasPane ? 'thread' : 'list' ?>">
        <?php require __DIR__ . '/_sidebar.php'; ?>

        <section class="rb-chat-main" aria-label="Conversation">
            <div class="rb-chat-placeholder" data-chat-placeholder<?= $hasPane ? ' hidden' : '' ?>>
                <p>Choisissez une conversation.</p>
            </div>
            <div class="rb-chat-thread" data-chat-thread<?= $hasPane ? '' : ' hidden' ?>>
                <?php require __DIR__ . '/_thread-header.php'; ?>
                <rb-message-list class="rb-chat-messages" data-chat-messages aria-live="polite"><ol class="rb-chat-messages-list"><?php $rows = $thread['rows'] ?? []; require __DIR__ . '/_rows.php'; ?></ol></rb-message-list>
                <?php if ($draft !== null && $draft['blocked']): ?>
                    <p class="rb-chat-notice" role="alert">Vous devez appartenir à un autre groupe pour écrire à celui-ci.</p>
                <?php endif; ?>
                <?php /* #187 : ancre de hauteur nulle posée entre le fil et la ligne d'état ; le bouton flotte juste au-dessus. */ ?>
                <div class="rb-chat-new-anchor"><button type="button" class="rb-chat-new-messages" data-chat-new-messages hidden>↓ Nouveaux messages</button></div>
                <p class="rb-chat-status<?= $thread !== null && $thread['typing'] ? ' rb-chat-status--typing' : '' ?>" data-chat-status aria-live="polite"><?= $thread !== null ? e($thread['status']) : '' ?></p>
                <?php $composerHidden = $draft !== null && $draft['blocked']; require __DIR__ . '/_composer.php'; ?>
            </div>
        </section>
    </rb-chat>
    <rb-confirm-modal></rb-confirm-modal>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
