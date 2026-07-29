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
            <a href="/" class="rb-btn rb-btn-primary rb-group-space-back">&larr; Retour</a>
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
            <section class="rb-group-space-section" data-group-space-editor data-endpoint="/api/groups/<?= e((string) $group->id()) ?>/space">
                <h2>Édition</h2>
                <form data-group-space-form>
                    <div class="rb-group-space-editor-group">
                        <h3>Line-up</h3>
                        <div data-lineup-editor-list>
                            <?php foreach ($group->lineup() as $member): ?>
                                <div class="rb-group-space-editor-row" data-lineup-row>
                                    <input type="text" name="name" class="rb-input" placeholder="Nom" value="<?= e($member->name()) ?>">
                                    <input type="text" name="instrument" class="rb-input" placeholder="Instrument" value="<?= e($member->instrument()) ?>">
                                    <button type="button" class="rb-btn rb-btn-danger" data-remove-row>Retirer</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="rb-btn" data-add-lineup-row>+ Ajouter un musicien</button>
                    </div>

                    <div class="rb-group-space-editor-group">
                        <h3>Concerts à venir</h3>
                        <div data-shows-editor-list>
                            <?php foreach ($group->upcomingShows() as $show): ?>
                                <div class="rb-group-space-editor-row" data-show-row>
                                    <input type="text" name="date" class="rb-input" placeholder="Date (AAAA-MM-JJ)" value="<?= e($show->date()) ?>">
                                    <input type="text" name="venue" class="rb-input" placeholder="Lieu" value="<?= e($show->venue()) ?>">
                                    <button type="button" class="rb-btn rb-btn-danger" data-remove-row>Retirer</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="rb-btn" data-add-show-row>+ Ajouter un concert</button>
                    </div>

                    <button type="submit" class="rb-btn rb-btn-primary">Enregistrer</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($currentUserGroupRole !== null): ?>
            <section class="rb-group-space-section">
                <h2>Documents techniques</h2>
                <ul class="rb-group-space-documents" data-documents-list>
                    <?php foreach ($documents as $document): ?>
                        <li data-document-id="<?= e((string) $document->id()) ?>">
                            <a href="/api/documents/<?= e((string) $document->id()) ?>" target="_blank" rel="noopener"><?= e($document->originalName()) ?></a>
                            <?php if ($currentUserGroupRole->value === 'gestionnaire'): ?>
                                <button type="button" class="rb-btn rb-btn-danger" data-delete-document data-document-id="<?= e((string) $document->id()) ?>">Supprimer</button>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($currentUserGroupRole->value === 'gestionnaire'): ?>
                    <form data-document-upload-form data-endpoint="/api/groups/<?= e((string) $group->id()) ?>/documents" enctype="multipart/form-data">
                        <div class="rb-field">
                            <label for="document">Ajouter un document (PDF, JPEG, PNG — 10 Mo max)</label>
                            <div class="rb-file-field">
                                <input type="file" id="document" name="document" accept="application/pdf,image/jpeg,image/png" class="rb-file-field-input" required>
                                <label for="document" class="rb-btn rb-file-field-trigger">Choisir un fichier</label>
                                <span class="rb-file-field-name" data-file-field-name>Aucun fichier choisi</span>
                            </div>
                        </div>
                        <button type="submit" class="rb-btn rb-btn-primary">Envoyer</button>
                    </form>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="rb-group-space-section">
                <button type="button" class="rb-btn rb-btn-primary" data-contact-group-id="<?= e((string) $group->id()) ?>" data-contact-group-name="<?= e($group->name()) ?>">Contacter ce groupe</button>
            </section>
        <?php endif; ?>
    </div>

    <div class="rb-modal-overlay" data-contact-modal-overlay hidden>
        <div class="rb-modal rb-card" role="dialog" aria-modal="true" aria-labelledby="contact-modal-title">
            <h2 id="contact-modal-title" data-contact-modal-title>Contacter le groupe</h2>
            <form data-contact-form>
                <input type="hidden" name="groupId" data-contact-group-id-input>
                <div class="rb-field">
                    <label for="contact-message">Message</label>
                    <textarea id="contact-message" name="message" class="rb-input" rows="4" required></textarea>
                </div>
                <div class="rb-modal-actions">
                    <button type="button" class="rb-btn" data-contact-modal-cancel>Annuler</button>
                    <button type="submit" class="rb-btn rb-btn-primary">Envoyer</button>
                </div>
            </form>
        </div>
    </div>
    <?php require __DIR__ . '/../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
