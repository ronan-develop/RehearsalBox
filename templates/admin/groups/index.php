<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <title>Admin — Groupes — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
</head>
<body>
    <div class="rb-admin-page">
        <h1>Groupes</h1>

        <form data-async data-endpoint="/api/admin/groups" data-method="POST" class="rb-admin-form rb-card">
            <div class="rb-field">
                <label for="name">Nom du groupe</label>
                <input type="text" id="name" name="name" class="rb-input" required maxlength="120">
            </div>
            <div class="rb-field">
                <label for="genre">Genre</label>
                <input type="text" id="genre" name="genre" class="rb-input" maxlength="60">
            </div>
            <div class="rb-field">
                <label for="colorHex">Couleur</label>
                <input type="color" id="colorHex" name="colorHex" value="#b5654a">
            </div>
            <div class="rb-field">
                <label for="contactEmail">Email de contact</label>
                <input type="email" id="contactEmail" name="contactEmail" class="rb-input" required maxlength="190">
            </div>
            <button type="submit" class="rb-btn-primary">Créer le groupe</button>
        </form>

        <div class="rb-group-list" data-group-list>
            <?php foreach ($groups as $group): ?>
                <article class="rb-group-card rb-card" data-group-id="<?= e((string) $group->id()) ?>">
                    <h3><?= e($group->name()) ?></h3>
                    <?php if ($group->genre() !== null): ?>
                        <p class="rb-group-genre"><?= e($group->genre()) ?></p>
                    <?php endif; ?>
                    <form data-async data-endpoint="/api/admin/groups/<?= e((string) $group->id()) ?>"
                          data-method="PATCH" class="rb-edit-group-form" hidden>
                        <input type="text" name="name" class="rb-input" value="<?= e($group->name()) ?>" required maxlength="120">
                        <input type="text" name="genre" class="rb-input" value="<?= e($group->genre() ?? '') ?>" maxlength="60">
                        <input type="color" name="colorHex" value="<?= e($group->colorHex() ?? '#b5654a') ?>">
                        <input type="email" name="contactEmail" class="rb-input" value="<?= e($group->contactEmail()) ?>" required maxlength="190">
                        <button type="submit" class="rb-btn">Enregistrer</button>
                    </form>
                    <div class="rb-group-actions">
                        <button type="button" class="rb-btn" data-edit-group-button
                                data-group-id="<?= e((string) $group->id()) ?>">Modifier</button>
                        <button type="button" class="rb-btn rb-btn-danger" data-delete-group-button
                                data-group-id="<?= e((string) $group->id()) ?>">Supprimer</button>
                    </div>
                    <form data-async data-endpoint="/api/admin/groups/<?= e((string) $group->id()) ?>/members"
                          data-method="POST" class="rb-add-member-form">
                        <input type="email" name="email" class="rb-input" placeholder="Email du musicien" required>
                        <button type="submit" class="rb-btn">Ajouter</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
    <?php require __DIR__ . '/../../partials/nav.php'; ?>
    <rb-confirm-modal></rb-confirm-modal>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
