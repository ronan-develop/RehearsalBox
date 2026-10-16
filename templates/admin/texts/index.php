<?php
/**
 * Textes de l'association (#472) : liste des textes et création d'un texte. La création est un bloc (data-text-create) dont le module JS
 * appelle l'API ; aucun envoi de formulaire natif.
 *
 * @var string $csrfToken
 * @var list<array{id: int, title: string, updated: string, by: string}> $rows
 * @var \App\Account\Entity\UserRole $currentUserRole
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <title>Admin — Textes de l'association — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
    <link rel="stylesheet" href="/assets/css/pages/association-texts.css">
</head>
<body>
    <div class="rb-admin-page">
        <?php $backHref = '/admin/assemblies'; $backLabel = 'Retour aux assemblées'; require __DIR__ . '/../../partials/back-button.php'; ?>
        <h1>Textes de l'association</h1>
        <p class="rb-admin-subtitle">Statuts et autres textes, visibles des administrateurs seulement.</p>

        <ul class="rb-text-list" data-text-list>
            <?php foreach ($rows as $row): ?>
                <li>
                    <a href="/admin/texts/<?= e((string) $row['id']) ?>" class="rb-card rb-text-card">
                        <span class="rb-text-card-title"><?= e($row['title']) ?></span>
                        <span class="rb-text-card-meta">Modifié le <?= e($row['updated']) ?> par <?= e($row['by']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="rb-admin-note"<?= $rows === [] ? '' : ' hidden' ?>>Aucun texte pour l'instant.</p>

        <section data-text-section>
            <button type="button" class="rb-btn rb-btn-primary" data-action="new">Nouveau texte</button>

            <div class="rb-card rb-text-create" data-text-create hidden>
                <h2>Nouveau texte</h2>
                <div class="rb-field">
                    <label for="text-create-title">Titre</label>
                    <input type="text" id="text-create-title" name="title" maxlength="150" class="rb-input">
                    <p class="rb-field-error" role="alert" data-field-error="title" hidden></p>
                </div>
                <div class="rb-field">
                    <label for="text-create-body">Texte</label>
                    <div class="rb-text-format" role="group" aria-label="Mise en forme">
                        <button type="button" class="rb-btn rb-btn-toggle" data-action="format" data-format="subtitle" aria-pressed="false">Sous-titre</button>
                        <button type="button" class="rb-btn rb-btn-toggle" data-action="format" data-format="list" aria-pressed="false">Liste</button>
                    </div>
                    <textarea id="text-create-body" name="body" maxlength="100000" rows="14" class="rb-input"></textarea>
                    <p class="rb-field-error" role="alert" data-field-error="body" hidden></p>
                </div>
                <p class="rb-field-error" role="alert" data-form-error hidden></p>
                <div class="rb-text-actions">
                    <button type="button" class="rb-btn rb-btn-primary" data-action="create">Enregistrer le texte</button>
                    <button type="button" class="rb-btn" data-action="cancel-create">Annuler</button>
                </div>
            </div>
        </section>

    </div>

    <?php require __DIR__ . '/../../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
