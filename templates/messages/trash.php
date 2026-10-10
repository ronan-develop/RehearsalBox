<?php
/**
 * Corbeille de la messagerie (#190) : les conversations que la personne a ouvertes puis supprimées. Restaurables pendant
 * 30 jours, ensuite supprimées pour de bon. Page rendue par le serveur ; les boutons passent par messages-trash.js.
 *
 * @var string $csrfToken
 * @var list<array{id: int, title: string, deletedOn: string, daysLeft: int}> $items
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <title>Corbeille — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/messages.css">
</head>
<body class="rb-chat-page">
    <main class="rb-trash">
        <header class="rb-chat-sidebar-head rb-trash-head">
            <a href="/messages" class="rb-back-link" aria-label="Retour aux conversations"><?php require __DIR__ . '/../partials/icon-back.php'; ?></a>
            <h1>Corbeille</h1>
        </header>
        <p class="rb-trash-help">Les conversations supprimées restent ici 30 jours. Passé ce délai, elles disparaissent définitivement.</p>
        <ul class="rb-trash-list" data-trash-list>
            <?php foreach ($items as $item): ?>
                <li class="rb-trash-item" data-trash-item>
                    <div class="rb-trash-info">
                        <span class="rb-trash-title"><?= e($item['title']) ?></span>
                        <span class="rb-trash-meta">Supprimée le <?= e($item['deletedOn']) ?> · <?= e((string) $item['daysLeft']) ?> jour<?= $item['daysLeft'] > 1 ? 's' : '' ?> restant<?= $item['daysLeft'] > 1 ? 's' : '' ?></span>
                    </div>
                    <div class="rb-trash-actions">
                        <button type="button" class="rb-btn" data-trash-action="restore" data-id="<?= e((string) $item['id']) ?>">Restaurer</button>
                        <button type="button" class="rb-btn rb-btn-danger" data-trash-action="purge" data-id="<?= e((string) $item['id']) ?>">Supprimer définitivement</button>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="rb-chat-empty" data-trash-empty<?= $items === [] ? '' : ' hidden' ?>>La corbeille est vide.</p>
        <p class="rb-chat-notice" role="alert" data-trash-error hidden></p>
    </main>
    <?php require __DIR__ . '/../partials/confirm-dialog.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
