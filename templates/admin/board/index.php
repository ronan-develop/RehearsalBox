<?php
/**
 * Bureau de l'association (#478) : un bloc par poste (compte titulaire et prénom et nom imprimés dans les comptes rendus). Le composant
 * JS de la page appelle l'API pour enregistrer ou vider un poste ; les valeurs sont échappées ici.
 *
 * @var string $csrfToken
 * @var list<array{post: string, label: string, userId: string, fullName: string, options: list<array{id: string, name: string}>}> $posts
 * @var \App\Account\Entity\UserRole $currentUserRole
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <title>Admin — Bureau de l'association — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
    <link rel="stylesheet" href="/assets/css/pages/association-board.css">
</head>
<body>
    <div class="rb-admin-page">
        <?php $backHref = '/admin/assemblies'; $backLabel = 'Retour aux assemblées'; require __DIR__ . '/../../partials/back-button.php'; ?>
        <h1>Bureau de l'association</h1>
        <p class="rb-admin-subtitle">Le compte titulaire de chaque poste et son prénom et nom, tels qu'ils figurent dans les comptes rendus. Un poste vide laisse le choix libre.</p>

        <div data-board>
            <?php foreach ($posts as $row): ?>
                <section class="rb-card rb-board-post" data-board-post="<?= e($row['post']) ?>">
                    <h2><?= e($row['label']) ?></h2>
                    <div class="rb-field">
                        <label for="board-<?= e($row['post']) ?>-user">Compte</label>
                        <select id="board-<?= e($row['post']) ?>-user" name="userId" class="rb-input">
                            <option value="">— Choisir —</option>
                            <?php foreach ($row['options'] as $option): ?>
                                <option value="<?= e($option['id']) ?>"<?= $option['id'] === $row['userId'] ? ' selected' : '' ?>><?= e($option['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="rb-field-error" role="alert" data-field-error="userId" hidden></p>
                    </div>
                    <div class="rb-field">
                        <label for="board-<?= e($row['post']) ?>-name">Prénom et nom</label>
                        <input type="text" id="board-<?= e($row['post']) ?>-name" name="fullName" maxlength="120" class="rb-input" value="<?= e($row['fullName']) ?>">
                        <p class="rb-field-error" role="alert" data-field-error="fullName" hidden></p>
                    </div>
                    <p class="rb-field-error" role="alert" data-form-error hidden></p>
                    <div class="rb-board-actions">
                        <button type="button" class="rb-btn rb-btn-primary" data-action="save">Enregistrer</button>
                        <button type="button" class="rb-btn" data-action="vacate"<?= $row['userId'] === '' ? ' hidden' : '' ?>>Vider le poste</button>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </div>

    <?php require __DIR__ . '/../../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
