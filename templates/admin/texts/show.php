<?php
/**
 * Texte de l'association (#472) : lecture, puis modification (data-text-edit, masqué par défaut). Le composant JS de la section
 * appelle l'API pour enregistrer, supprimer ou revenir à la lecture ; les valeurs du panneau sont échappées ici.
 *
 * @var string $csrfToken
 * @var array{id: int, title: string, body: string, revision: int, updated: string, by: string} $text
 * @var \App\Account\Entity\UserRole $currentUserRole
 */
$textId = (string) $text['id'];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <title>Admin — <?= e($text['title']) ?> — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
    <link rel="stylesheet" href="/assets/css/pages/association-texts.css">
</head>
<body>
    <div class="rb-admin-page">
        <?php $backHref = '/admin/texts'; $backLabel = 'Retour aux textes'; require __DIR__ . '/../../partials/back-button.php'; ?>

        <section data-text data-id="<?= e($textId) ?>" data-revision="<?= e((string) $text['revision']) ?>">
            <div class="rb-card rb-text-view" data-text-view>
                <h1><?= e($text['title']) ?></h1>
                <p class="rb-text-meta">Modifié le <?= e($text['updated']) ?> par <?= e($text['by']) ?></p>
                <div class="rb-text-body"><?= nl2br(e($text['body'])) ?></div>
                <div class="rb-text-view-actions">
                    <button type="button" class="rb-btn" data-action="edit">Modifier</button>
                    <button type="button" class="rb-btn" data-action="print">Imprimer</button>
                </div>
                <p class="rb-text-print-stamp" data-print-stamp></p>
            </div>

            <div class="rb-card rb-text-edit" data-text-edit hidden>
                <div class="rb-field">
                    <label for="text-edit-title">Titre</label>
                    <input type="text" id="text-edit-title" name="title" maxlength="150" class="rb-input" value="<?= e($text['title']) ?>">
                    <p class="rb-field-error" role="alert" data-field-error="title" hidden></p>
                </div>
                <div class="rb-field">
                    <label for="text-edit-body">Texte</label>
                    <textarea id="text-edit-body" name="body" maxlength="100000" rows="18" class="rb-input"><?= e($text['body']) ?></textarea>
                    <p class="rb-field-error" role="alert" data-field-error="body" hidden></p>
                </div>
                <p class="rb-field-error" role="alert" data-form-error hidden></p>
                <div class="rb-text-actions">
                    <button type="button" class="rb-btn rb-btn-primary" data-action="save">Enregistrer</button>
                    <button type="button" class="rb-btn" data-action="cancel">Annuler</button>
                    <button type="button" class="rb-btn rb-btn-danger" data-action="delete">Supprimer</button>
                </div>
            </div>
        </section>
        <?php require __DIR__ . '/../../partials/confirm-dialog.php'; ?>
    </div>

    <?php require __DIR__ . '/../../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
