<?php
/**
 * Restauration de la base (#241) : réservée au propriétaire (le contrôleur répond 404 aux autres). Tout le contenu interactif est dans
 * <rb-restore> (public/assets/js/admin/restore/rb-restore.js) : le serveur rend le tableau et la fenêtre de confirmation, le JS ajoute
 * seulement le comportement. Les heures s'affichent dans le fuseau local.
 *
 * @var list<\App\Backup\Restore\BackupEntry> $backups
 * @var array{state: string, file: string, step: string, startedAt: string, updatedAt: string, message: string, safetyBackup: string}|null $state
 * @var bool $running
 * @var list<array{id: int, groupId: int, originalName: string}> $missingFiles
 * @var list<string> $orphanFiles
 * @var string $confirmWord
 * @var \App\Account\Entity\UserRole $currentUserRole
 * @var \DateTimeZone $localTimezone
 * @var string|null $csrfToken
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <title>Admin — Restauration — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
</head>
<body>
    <div class="rb-admin-page">
        <h1>Restauration de la base</h1>
        <div class="rb-card rb-restore-warning" role="note">
            <p><strong>Restaurer remplace toutes les données actuelles</strong> par celles de la sauvegarde choisie.</p>
            <ul>
                <li>Un dump de l'état actuel est fait automatiquement avant la restauration (« avant-restauration »).</li>
                <li>Les documents des groupes (fichiers) ne sont pas restaurés : voir la section en bas de page.</li>
                <li>L'application peut être indisponible quelques instants.</li>
            </ul>
        </div>

        <?php if ($state !== null): ?>
            <?php if ($state['state'] === 'running' && $running): ?>
                <section class="rb-card rb-restore-status" data-restore-state="running" role="status">
                    <p><strong>Restauration en cours</strong> — étape : <?= e($state['step']) ?></p>
                </section>
            <?php elseif ($state['state'] === 'running'): ?>
                <section class="rb-card rb-restore-status" data-restore-state="interrupted" role="alert">
                    <p><strong>Restauration interrompue.</strong> Le processus s'est arrêté avant la fin.</p>
                    <?php if ($state['safetyBackup'] !== ''): ?>
                        <p>Dump de sécurité à remettre si besoin : <strong><?= e($state['safetyBackup']) ?></strong></p>
                    <?php endif; ?>
                </section>
            <?php elseif ($state['state'] === 'done'): ?>
                <section class="rb-card rb-restore-status" data-restore-state="done" role="status">
                    <p><strong>Restauration terminée.</strong></p>
                    <?php foreach (preg_split('/\R/', $state['message'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line): ?>
                        <p><?= e($line) ?></p>
                    <?php endforeach; ?>
                    <?php if ($state['safetyBackup'] !== ''): ?>
                        <p>Dump de sécurité de l'état précédent : <strong><?= e($state['safetyBackup']) ?></strong></p>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <section class="rb-card rb-restore-status" data-restore-state="failed" role="alert">
                    <p><strong>La restauration a échoué.</strong></p>
                    <?php foreach (preg_split('/\R/', $state['message'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line): ?>
                        <p><?= e($line) ?></p>
                    <?php endforeach; ?>
                    <?php if ($state['safetyBackup'] !== ''): ?>
                        <p>Dump de sécurité à remettre en place : <strong><?= e($state['safetyBackup']) ?></strong></p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <rb-restore endpoint="/api/admin/restore" confirm-word="<?= e($confirmWord) ?>">
            <section class="rb-restore-section" aria-labelledby="restore-backups-title">
                <h2 id="restore-backups-title">Sauvegardes</h2>
                <?php if ($backups === []): ?>
                    <p class="rb-admin-note">Aucune sauvegarde disponible.</p>
                <?php else: ?>
                    <div class="rb-admin-table-wrapper">
                        <table class="rb-admin-table rb-restore-table">
                            <thead>
                                <tr><th scope="col">Date et heure</th><th scope="col">Type</th><th scope="col">Fichier</th><th scope="col">Taille</th><th></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($backups as $backup): ?>
                                    <?php
                                    $when = $backup->createdAt->setTimezone($localTimezone)->format('d/m/Y à H:i');
                                    if ($backup->kind === 'daily') {
                                        $type = 'Quotidienne';
                                    } elseif (str_ends_with($backup->label, '-avant-restauration')) {
                                        $type = 'Avant restauration';
                                    } else {
                                        $type = 'Avant déploiement';
                                    }
                                    ?>
                                    <tr>
                                        <td><?= e($when) ?></td>
                                        <td><?= e($type) ?></td>
                                        <td><?= e($backup->file) ?></td>
                                        <td><?= e(\App\Metrics\Report\HumanSize::of($backup->bytes)) ?></td>
                                        <td>
                                            <button type="button" class="rb-btn rb-btn-danger" data-restore-file="<?= e($backup->file) ?>"
                                                    data-restore-label="<?= e($when) ?>"<?= $running ? ' disabled' : '' ?>>Restaurer</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <dialog class="rb-modal rb-card" role="alertdialog" aria-labelledby="restore-dialog-title" aria-describedby="restore-dialog-target" data-restore-dialog>
                <h2 class="rb-modal-title" id="restore-dialog-title">Restaurer la base ?</h2>
                <p class="rb-modal-text" id="restore-dialog-target" data-restore-target></p>
                <form class="rb-admin-form" novalidate>
                    <div class="rb-field">
                        <label for="restore-password">Votre mot de passe</label>
                        <input type="password" id="restore-password" name="password" class="rb-input" autocomplete="current-password" autofocus>
                    </div>
                    <div class="rb-field">
                        <label for="restore-confirmation">Tapez <?= e($confirmWord) ?> pour confirmer</label>
                        <input type="text" id="restore-confirmation" name="confirmation" class="rb-input" autocomplete="off" autocapitalize="characters">
                    </div>
                    <p class="rb-field-error" data-restore-error role="alert" hidden></p>
                    <div class="rb-modal-actions">
                        <button type="button" class="rb-btn rb-modal-cancel" data-restore-cancel>Annuler</button>
                        <button type="submit" class="rb-btn rb-modal-confirm">Restaurer maintenant</button>
                    </div>
                </form>
            </dialog>

            <p class="rb-admin-note" data-restore-done role="status" hidden></p>
        </rb-restore>

        <section class="rb-card rb-restore-section" aria-labelledby="restore-documents-title">
            <h2 id="restore-documents-title">Documents de groupes après restauration</h2>
            <?php if ($missingFiles === [] && $orphanFiles === []): ?>
                <p class="rb-admin-note">Aucune incohérence.</p>
            <?php else: ?>
                <?php if ($missingFiles !== []): ?>
                    <h3>Lignes sans fichier</h3>
                    <ul>
                        <?php foreach ($missingFiles as $document): ?>
                            <li><?= e($document['originalName']) ?> (groupe n° <?= e((string) $document['groupId']) ?>)</li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($orphanFiles !== []): ?>
                    <h3>Fichiers sans ligne</h3>
                    <ul>
                        <?php foreach ($orphanFiles as $name): ?>
                            <li><?= e($name) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
    <?php require __DIR__ . '/../../partials/nav.php'; ?>
    <?php require __DIR__ . '/../../partials/confirm-dialog.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
