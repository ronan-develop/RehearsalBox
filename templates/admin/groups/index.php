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

        <form data-async data-endpoint="/api/admin/groups" data-method="POST" class="rb-admin-form">
            <div class="rb-field">
                <label for="name">Nom du groupe</label>
                <input type="text" id="name" name="name" required maxlength="120">
            </div>
            <div class="rb-field">
                <label for="genre">Genre</label>
                <input type="text" id="genre" name="genre" maxlength="60">
            </div>
            <div class="rb-field">
                <label for="colorHex">Couleur</label>
                <input type="color" id="colorHex" name="colorHex" value="#e63946">
            </div>
            <button type="submit" class="rb-btn rb-auth-submit">Créer le groupe</button>
        </form>

        <div class="rb-group-list" data-group-list>
            <?php foreach ($groups as $group): ?>
                <article class="rb-group-card" data-group-id="<?= e((string) $group->id()) ?>">
                    <h3><?= e($group->name()) ?></h3>
                    <?php if ($group->genre() !== null): ?>
                        <p class="rb-group-genre"><?= e($group->genre()) ?></p>
                    <?php endif; ?>
                    <form data-async data-endpoint="/api/admin/groups/<?= e((string) $group->id()) ?>/members"
                          data-method="POST" class="rb-add-member-form">
                        <input type="email" name="email" placeholder="Email du musicien" required>
                        <button type="submit" class="rb-btn">Ajouter</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
