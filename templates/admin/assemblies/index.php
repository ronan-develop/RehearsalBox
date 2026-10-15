<?php
/**
 * Comptes rendus d'assemblée générale (#361) : liste des comptes rendus et création d'un brouillon. La création est un composant
 * JS (data-assembly-create) qui appelle l'API ; aucun envoi de formulaire natif.
 *
 * @var string $csrfToken
 * @var list<array{id: int, title: string, date: string, status: string, version: int, url: string}> $rows
 * @var \App\Account\Entity\UserRole $currentUserRole
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <title>Admin — Assemblées — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
    <link rel="stylesheet" href="/assets/css/pages/assembly.css">
</head>
<body>
    <div class="rb-admin-page">
        <?php $backHref = '/'; $backLabel = 'Retour aux disponibilités'; require __DIR__ . '/../../partials/back-button.php'; ?>
        <?php $adminTab = 'assemblies'; require __DIR__ . '/../../partials/admin-tabs.php'; ?>
        <h1>Assemblées</h1>
        <p class="rb-admin-subtitle">Comptes rendus des assemblées générales de l'association.</p>

        <ul class="rb-assembly-list">
            <li>
                <a href="/admin/texts" class="rb-card rb-assembly-card">
                    <span class="rb-assembly-card-title">Statuts et textes de l'association</span>
                    <span class="rb-assembly-card-meta"><span>Consulter et modifier</span></span>
                </a>
            </li>
            <li>
                <a href="/admin/board" class="rb-card rb-assembly-card">
                    <span class="rb-assembly-card-title">Bureau de l'association</span>
                    <span class="rb-assembly-card-meta"><span>Président, trésorier, secrétaire</span></span>
                </a>
            </li>
        </ul>

        <ul class="rb-assembly-list" data-assembly-list>
            <?php foreach ($rows as $row): ?>
                <li>
                    <a href="<?= e($row['url']) ?>" class="rb-card rb-assembly-card">
                        <span class="rb-assembly-card-title"><?= e($row['title']) ?></span>
                        <span class="rb-assembly-card-meta">
                            <span><?= e($row['date']) ?></span>
                            <span><?= e($row['status']) ?></span>
                            <?php if ($row['version'] > 1): ?>
                                <span>version <?= e((string) $row['version']) ?></span>
                            <?php endif; ?>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="rb-admin-note"<?= $rows === [] ? '' : ' hidden' ?>>Aucun compte rendu.</p>

        <div class="rb-card rb-assembly-create" data-assembly-create>
            <div class="rb-field">
                <label for="assembly-create-kind">Type d'assemblée</label>
                <select id="assembly-create-kind" name="kind" class="rb-input">
                    <option value="ordinaire">Ordinaire</option>
                    <option value="extraordinaire">Extraordinaire</option>
                </select>
            </div>
            <div class="rb-field">
                <label for="assembly-create-heldOn">Date de l'assemblée</label>
                <input type="date" id="assembly-create-heldOn" name="heldOn" class="rb-input">
            </div>
            <button type="button" class="rb-btn rb-btn-primary" data-action="create">Nouveau compte rendu</button>
            <p class="rb-field-error" role="alert" data-field-error="create" hidden></p>
        </div>
    </div>

    <?php require __DIR__ . '/../../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
