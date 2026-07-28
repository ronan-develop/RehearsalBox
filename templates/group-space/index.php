<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <title><?= e($group->name()) ?> — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/group-space.css">
</head>
<body>
    <div class="rb-group-space-page" data-group-space data-group-id="<?= e((string) $group->id()) ?>" data-current-user-group-role="<?= e($currentUserGroupRole?->value ?? '') ?>">
        <header class="rb-group-space-header">
            <a href="/" class="rb-group-space-back">&larr; Retour</a>
            <h1><?= e($group->name()) ?></h1>
            <?php if ($group->genre() !== null): ?>
                <p class="rb-group-space-genre"><?= e($group->genre()) ?></p>
            <?php endif; ?>
        </header>

        <section class="rb-group-space-section">
            <h2>Line-up</h2>
            <ul class="rb-group-space-lineup" data-lineup-list>
                <?php foreach ($group->lineup() as $member): ?>
                    <li><?= e($member->name()) ?> — <?= e($member->instrument()) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="rb-group-space-section">
            <h2>Concerts à venir</h2>
            <ul class="rb-group-space-shows" data-shows-list>
                <?php foreach ($group->upcomingShows() as $show): ?>
                    <li><?= e($show->date()) ?> — <?= e($show->venue()) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <?php if ($currentUserGroupRole?->value === 'gestionnaire'): ?>
            <section class="rb-group-space-section" data-group-space-editor>
                <h2>Édition</h2>
                <form data-group-space-form data-endpoint="/api/groups/<?= e((string) $group->id()) ?>/space" data-method="PATCH">
                    <p class="rb-field-hint">Édition disponible pour les membres gestionnaires.</p>
                    <button type="submit" class="rb-btn rb-btn-primary">Enregistrer</button>
                </form>
            </section>
        <?php endif; ?>
    </div>
    <?php require __DIR__ . '/../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
