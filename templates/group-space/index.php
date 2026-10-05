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
        <header class="rb-group-space-header rb-stone-panel">
            <a href="/" class="rb-btn rb-btn-primary rb-group-space-back">&larr; Retour</a>
            <h1><?= e($group->name()) ?></h1>
            <?php if ($group->genre() !== null): ?>
                <p class="rb-group-space-genre"><?= e($group->genre()) ?></p>
            <?php endif; ?>
        </header>

        <section class="rb-group-space-section">
            <h2>Line-up</h2>
            <?php if ($group->lineup() === []): ?>
                <p class="rb-group-space-empty">Aucun musicien renseigné.</p>
            <?php else: ?>
                <ul class="rb-group-space-lineup" data-lineup-list>
                    <?php foreach ($group->lineup() as $member): ?>
                        <li><span><?= e($member->name()) ?></span><span><?= e($member->instrument()) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="rb-group-space-section">
            <h2>Concerts à venir</h2>
            <?php if ($group->upcomingShows() === []): ?>
                <p class="rb-group-space-empty">Aucun concert à venir.</p>
            <?php else: ?>
                <ul class="rb-group-space-shows" data-shows-list>
                    <?php foreach ($group->upcomingShows() as $show): ?>
                        <li><span class="rb-group-space-show-date"><?= e($show->date()) ?></span><span><?= e($show->venue()) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
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
                                    <button type="button" class="rb-btn rb-btn-danger rb-btn-icon" data-remove-row aria-label="Retirer">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                        </svg>
                                    </button>
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
                                    <button type="button" class="rb-btn rb-btn-danger rb-btn-icon" data-remove-row aria-label="Retirer">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                        </svg>
                                    </button>
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
                <?php if ($documents === []): ?>
                    <p class="rb-group-space-empty">Aucun document.</p>
                <?php else: ?>
                    <ul class="rb-group-space-documents" data-documents-list>
                        <?php foreach ($documents as $document): ?>
                            <li data-document-id="<?= e((string) $document->id()) ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/>
                                </svg>
                                <a href="/api/documents/<?= e((string) $document->id()) ?>" target="_blank" rel="noopener"><?= e($document->originalName()) ?></a>
                                <?php if ($currentUserGroupRole->value === 'gestionnaire'): ?>
                                    <button type="button" class="rb-btn rb-btn-danger rb-btn-icon" data-delete-document data-document-id="<?= e((string) $document->id()) ?>" aria-label="Supprimer">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                        </svg>
                                    </button>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
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
                <a href="/messages/new/<?= e((string) $group->id()) ?>" class="rb-btn rb-btn-primary">Contacter ce groupe</a>
            </section>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . '/../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
