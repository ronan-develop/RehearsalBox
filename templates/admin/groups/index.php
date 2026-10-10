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
        <?php $adminTab = 'groups'; require __DIR__ . '/../../partials/admin-tabs.php'; ?>
        <h1>Groupes</h1>
        <p class="rb-admin-subtitle">Création et gestion des membres.</p>

        <rb-async-form endpoint="/api/admin/groups" method="POST"><form class="rb-admin-form rb-card">
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
                <rb-color-picker><input type="text" id="colorHex" name="colorHex" class="rb-input" value="<?= e(\App\Support\SafeColor::DEFAULT) ?>" maxlength="7" pattern="#[0-9a-fA-F]{6}" autocomplete="off" spellcheck="false"></rb-color-picker>
            </div>
            <div class="rb-field">
                <label for="contactEmail">Email de contact</label>
                <input type="email" id="contactEmail" name="contactEmail" class="rb-input" required maxlength="190">
            </div>
            <button type="submit" class="rb-btn-primary">Créer le groupe</button>
        </form></rb-async-form>

        <div class="rb-group-list" data-group-list>
            <?php foreach ($groups as $group): ?>
                <?php $groupId = (string) $group->id(); ?>
                <article class="rb-group-card rb-card" data-group-id="<?= e($groupId) ?>">
                    <header class="rb-group-card-head">
                        <div class="rb-group-card-identity">
                            <h3><?= e($group->name()) ?></h3>
                            <?php if ($group->genre() !== null): ?>
                                <p class="rb-group-genre"><?= e($group->genre()) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="rb-group-actions">
                            <button type="button" class="rb-btn rb-btn-icon" data-edit-group-button
                                    data-group-id="<?= e($groupId) ?>" aria-label="Modifier">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M12 20h9"/>
                                    <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                                </svg>
                            </button>
                            <?php $impact = $impacts[$group->id()] ?? ['members' => 0, 'conversations' => 0, 'documents' => 0, 'requests' => 0]; ?>
                            <button type="button" class="rb-btn rb-btn-danger rb-btn-icon" data-delete-group-button
                                    data-group-id="<?= e($groupId) ?>"
                                    data-members-count="<?= e((string) $impact['members']) ?>" data-conversations-count="<?= e((string) $impact['conversations']) ?>"
                                    data-documents-count="<?= e((string) $impact['documents']) ?>" data-requests-count="<?= e((string) $impact['requests']) ?>"
                                    aria-label="Supprimer">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                    <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/>
                                </svg>
                            </button>
                        </div>
                    </header>
                    <rb-async-form endpoint="/api/admin/groups/<?= e($groupId) ?>" method="PATCH"><form class="rb-edit-group-form" hidden>
                        <div class="rb-field rb-group-field-name">
                            <label for="group-<?= e($groupId) ?>-name">Nom du groupe</label>
                            <input type="text" id="group-<?= e($groupId) ?>-name" name="name" class="rb-input" value="<?= e($group->name()) ?>" required maxlength="120">
                        </div>
                        <div class="rb-field rb-group-field-genre">
                            <label for="group-<?= e($groupId) ?>-genre">Genre</label>
                            <input type="text" id="group-<?= e($groupId) ?>-genre" name="genre" class="rb-input" value="<?= e($group->genre() ?? '') ?>" maxlength="60">
                        </div>
                        <div class="rb-field rb-group-field-color">
                            <label for="group-<?= e($groupId) ?>-colorHex">Couleur</label>
                            <rb-color-picker><input type="text" id="group-<?= e($groupId) ?>-colorHex" name="colorHex" class="rb-input" value="<?= e(\App\Support\SafeColor::from($group->colorHex()) ?? \App\Support\SafeColor::DEFAULT) ?>" maxlength="7" pattern="#[0-9a-fA-F]{6}" autocomplete="off" spellcheck="false"></rb-color-picker>
                        </div>
                        <div class="rb-field rb-group-field-contact">
                            <label for="group-<?= e($groupId) ?>-contactEmail">Email de contact</label>
                            <input type="email" id="group-<?= e($groupId) ?>-contactEmail" name="contactEmail" class="rb-input" value="<?= e($group->contactEmail()) ?>" required maxlength="190">
                        </div>
                        <div class="rb-group-form-actions">
                            <button type="submit" class="rb-btn-primary">Enregistrer</button>
                        </div>
                    </form></rb-async-form>
                    <section class="rb-group-members">
                        <rb-async-form endpoint="/api/admin/groups/<?= e($groupId) ?>/members" method="POST"><form class="rb-add-member-form">
                            <label for="group-<?= e($groupId) ?>-member-email">Ajouter un membre</label>
                            <div class="rb-add-member-row">
                                <input type="email" id="group-<?= e($groupId) ?>-member-email" name="email" class="rb-input" placeholder="Email du musicien" required>
                                <button type="submit" class="rb-btn rb-btn-icon" aria-label="Ajouter">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path d="M12 5v14M5 12h14"/>
                                    </svg>
                                </button>
                            </div>
                        </form></rb-async-form>
                    </section>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
    <?php require __DIR__ . '/../../partials/nav.php'; ?>
    <?php require __DIR__ . '/../../partials/confirm-dialog.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
