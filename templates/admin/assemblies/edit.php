<?php
/**
 * Compte rendu d'assemblée générale (#361) : brouillon éditable (composant <rb-assembly-form>, qui appelle l'API) ou, une fois validé,
 * lecture seule avec lien d'impression et ouverture d'une correction. Les lignes (présences, ordre du jour, résolutions) sont rendues
 * par les partiels ; le <template> de chaque liste sert à en ajouter une.
 *
 * @var string $csrfToken
 * @var array{id: int, revision: int, version: int, isDraft: bool, title: string, statusLabel: string, successorId: ?int, kind: string, heldOn: string, location: string, openedAt: string, closedAt: string, quorum: string, moralReport: string, financialReport: string, otherBusiness: string, board: array<string, array{label: string, userId: string}>, users: list<array{id: string, name: string}>, attendances: list<string>, attendees: list<array{userId: string, name: string, attendance: string, proxyHolder: string}>, agenda: list<array{title: string, discussion: string}>, resolutions: list<array{text: string, for: string, against: string, abstain: string, outcome: string}>} $form
 * @var \App\Account\Entity\UserRole $currentUserRole
 */
$assemblyId = (string) $form['id'];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <title>Admin — Assemblée — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
    <link rel="stylesheet" href="/assets/css/pages/assembly.css">
</head>
<body>
    <div class="rb-admin-page">
        <?php $backHref = '/admin/assemblies'; $backLabel = 'Retour aux assemblées'; require __DIR__ . '/../../partials/back-button.php'; ?>
        <?php $adminTab = 'assemblies'; require __DIR__ . '/../../partials/admin-tabs.php'; ?>
        <h1><?= e($form['title']) ?></h1>
        <p class="rb-admin-subtitle">
            <?= e($form['statusLabel']) ?>
            <?php if ($form['version'] > 1): ?>
                · version <?= e((string) $form['version']) ?>
            <?php endif; ?>
        </p>

        <?php if (!$form['isDraft']): ?>
            <div class="rb-card rb-assembly-readonly">
                <a class="rb-btn rb-btn-primary" href="/admin/assemblies/<?= e($assemblyId) ?>/print">Voir et imprimer</a>
                <?php if ($form['successorId'] === null): ?>
                    <div class="rb-assembly-correct" data-assembly-correct data-id="<?= e($assemblyId) ?>">
                        <button type="button" class="rb-btn" data-action="open-correct" aria-expanded="false" aria-controls="assembly-correct-panel">Corriger ce compte rendu</button>
                        <div class="rb-assembly-correct-panel" id="assembly-correct-panel" data-correct-panel hidden>
                            <div class="rb-field">
                                <label for="assembly-correct-reason">Motif de la correction</label>
                                <input type="text" id="assembly-correct-reason" name="reason" maxlength="255" class="rb-input">
                                <p class="rb-field-error" role="alert" data-field-error="reason" hidden></p>
                            </div>
                            <button type="button" class="rb-btn rb-btn-primary" data-action="correct">Ouvrir la correction</button>
                        </div>
                    </div>
                <?php else: ?>
                    <p><a href="/admin/assemblies/<?= e((string) $form['successorId']) ?>">Une correction existe : voir la version suivante</a></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <rb-assembly-form data-id="<?= e($assemblyId) ?>" data-revision="<?= e((string) $form['revision']) ?>">
                <fieldset class="rb-assembly-section">
                    <legend>En-tête</legend>
                    <div class="rb-field">
                        <label for="assembly-kind">Type d'assemblée</label>
                        <select id="assembly-kind" name="kind" class="rb-input">
                            <option value="ordinaire"<?= $form['kind'] === 'ordinaire' ? ' selected' : '' ?>>Ordinaire</option>
                            <option value="extraordinaire"<?= $form['kind'] === 'extraordinaire' ? ' selected' : '' ?>>Extraordinaire</option>
                        </select>
                        <p class="rb-field-error" role="alert" data-field-error="kind" hidden></p>
                    </div>
                    <div class="rb-field">
                        <label for="assembly-heldOn">Date de l'assemblée</label>
                        <input type="date" id="assembly-heldOn" name="heldOn" class="rb-input" value="<?= e($form['heldOn']) ?>">
                        <p class="rb-field-error" role="alert" data-field-error="heldOn" hidden></p>
                    </div>
                    <div class="rb-field">
                        <label for="assembly-location">Lieu</label>
                        <input type="text" id="assembly-location" name="location" class="rb-input" maxlength="200" value="<?= e($form['location']) ?>">
                        <p class="rb-field-error" role="alert" data-field-error="location" hidden></p>
                    </div>
                    <div class="rb-field">
                        <label for="assembly-openedAt">Heure d'ouverture</label>
                        <input type="time" id="assembly-openedAt" name="openedAt" class="rb-input" value="<?= e($form['openedAt']) ?>">
                        <p class="rb-field-error" role="alert" data-field-error="openedAt" hidden></p>
                    </div>
                    <div class="rb-field">
                        <label for="assembly-closedAt">Heure de clôture</label>
                        <input type="time" id="assembly-closedAt" name="closedAt" class="rb-input" value="<?= e($form['closedAt']) ?>">
                        <p class="rb-field-error" role="alert" data-field-error="closedAt" hidden></p>
                    </div>
                </fieldset>

                <fieldset class="rb-assembly-section">
                    <legend>Bureau</legend>
                    <?php foreach (['president', 'tresorier', 'secretaire'] as $post): ?>
                        <?php $seat = $form['board'][$post]; ?>
                        <div class="rb-field">
                            <label for="assembly-board-<?= e($post) ?>"><?= e($seat['label']) ?></label>
                            <select id="assembly-board-<?= e($post) ?>" name="board.<?= e($post) ?>" class="rb-input">
                                <option value="">— Choisir —</option>
                                <?php foreach ($form['users'] as $user): ?>
                                    <option value="<?= e($user['id']) ?>"<?= $user['id'] === $seat['userId'] ? ' selected' : '' ?>><?= e($user['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="rb-field-error" role="alert" data-field-error="board.<?= e($post) ?>" hidden></p>
                        </div>
                    <?php endforeach; ?>
                </fieldset>

                <fieldset class="rb-assembly-section">
                    <legend>Présences</legend>
                    <div class="rb-assembly-rows" data-rows="attendees">
                        <?php foreach ($form['attendees'] as $row): ?>
                            <?php require __DIR__ . '/_attendee-row.php'; ?>
                        <?php endforeach; ?>
                    </div>
                    <template data-row-template="attendees">
                        <?php $row = ['userId' => '', 'name' => '', 'attendance' => 'present', 'proxyHolder' => '']; ?>
                        <?php require __DIR__ . '/_attendee-row.php'; ?>
                    </template>
                    <button type="button" class="rb-btn" data-add-row="attendees">Ajouter une personne</button>
                    <p class="rb-field-error" role="alert" data-field-error="attendees" hidden></p>
                    <div class="rb-field">
                        <label for="assembly-quorum">Quorum</label>
                        <select id="assembly-quorum" name="quorum" class="rb-input">
                            <option value="">—</option>
                            <option value="1"<?= $form['quorum'] === '1' ? ' selected' : '' ?>>Quorum atteint</option>
                            <option value="0"<?= $form['quorum'] === '0' ? ' selected' : '' ?>>Quorum non atteint</option>
                        </select>
                    </div>
                </fieldset>

                <fieldset class="rb-assembly-section">
                    <legend>Ordre du jour</legend>
                    <div class="rb-assembly-rows" data-rows="agenda">
                        <?php foreach ($form['agenda'] as $row): ?>
                            <?php require __DIR__ . '/_agenda-row.php'; ?>
                        <?php endforeach; ?>
                    </div>
                    <template data-row-template="agenda">
                        <?php $row = ['title' => '', 'discussion' => '']; ?>
                        <?php require __DIR__ . '/_agenda-row.php'; ?>
                    </template>
                    <button type="button" class="rb-btn" data-add-row="agenda">Ajouter un point</button>
                    <p class="rb-field-error" role="alert" data-field-error="agenda" hidden></p>
                </fieldset>

                <fieldset class="rb-assembly-section">
                    <legend>Résolutions</legend>
                    <div class="rb-assembly-rows" data-rows="resolutions">
                        <?php foreach ($form['resolutions'] as $row): ?>
                            <?php require __DIR__ . '/_resolution-row.php'; ?>
                        <?php endforeach; ?>
                    </div>
                    <template data-row-template="resolutions">
                        <?php $row = ['text' => '', 'for' => '', 'against' => '', 'abstain' => '', 'outcome' => '']; ?>
                        <?php require __DIR__ . '/_resolution-row.php'; ?>
                    </template>
                    <button type="button" class="rb-btn" data-add-row="resolutions">Ajouter une résolution</button>
                    <p class="rb-field-error" role="alert" data-field-error="resolutions" hidden></p>
                </fieldset>

                <fieldset class="rb-assembly-section">
                    <legend>Rapports et questions diverses</legend>
                    <div class="rb-field">
                        <label for="assembly-moralReport">Rapport moral</label>
                        <textarea id="assembly-moralReport" name="moralReport" class="rb-input" maxlength="20000" rows="4"><?= e($form['moralReport']) ?></textarea>
                        <p class="rb-field-error" role="alert" data-field-error="moralReport" hidden></p>
                    </div>
                    <div class="rb-field">
                        <label for="assembly-financialReport">Rapport financier</label>
                        <textarea id="assembly-financialReport" name="financialReport" class="rb-input" maxlength="20000" rows="4"><?= e($form['financialReport']) ?></textarea>
                        <p class="rb-field-error" role="alert" data-field-error="financialReport" hidden></p>
                    </div>
                    <div class="rb-field">
                        <label for="assembly-otherBusiness">Questions diverses</label>
                        <textarea id="assembly-otherBusiness" name="otherBusiness" class="rb-input" maxlength="20000" rows="4"><?= e($form['otherBusiness']) ?></textarea>
                        <p class="rb-field-error" role="alert" data-field-error="otherBusiness" hidden></p>
                    </div>
                </fieldset>

                <p class="rb-admin-note">N'inscrivez aucune donnée sensible (santé, opinions…). Le motif d'une absence n'est pas demandé.</p>

                <div class="rb-assembly-actions">
                    <button type="button" class="rb-btn rb-btn-primary" data-action="save">Enregistrer le brouillon</button>
                    <button type="button" class="rb-btn" data-action="validate">Valider…</button>
                    <button type="button" class="rb-btn rb-btn-danger" data-action="delete">Supprimer le brouillon</button>
                    <a class="rb-btn" href="/admin/assemblies/<?= e($assemblyId) ?>/print">Aperçu imprimable</a>
                    <p class="rb-field-error" role="alert" data-form-error hidden></p>
                </div>
            </rb-assembly-form>
            <?php require __DIR__ . '/../../partials/confirm-dialog.php'; ?>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . '/../../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
