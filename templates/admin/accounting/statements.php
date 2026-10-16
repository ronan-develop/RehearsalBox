<?php
/**
 * Relevés de compte (#362) : pièces archivées de l'exercice choisi, avec l'écart entre le solde du relevé et celui du livre. Rendu
 * serveur ; le dépôt passe par l'API (JS). Tout texte est échappé ici ; aucun style ni script en ligne.
 *
 * @var string $csrfToken
 * @var array<string, mixed> $page voir StatementListView::page
 * @var \App\Account\Entity\UserRole $currentUserRole
 */
$year = $page['year'];
$adminTab = 'accounting';
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <title>Admin — Relevés de compte — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
    <link rel="stylesheet" href="/assets/css/pages/accounting.css">
</head>
<body>
    <div class="rb-admin-page">
        <?php $backHref = '/admin/accounting' . ($year === null ? '' : '?year=' . $year['id']); $backLabel = 'Retour à la comptabilité'; require __DIR__ . '/../../partials/back-button.php'; ?>
        <?php require __DIR__ . '/../../partials/admin-tabs.php'; ?>
        <h1>Relevés de compte</h1>
        <p class="rb-admin-subtitle">Les relevés de la banque, gardés avec le livre pour l'assemblée générale et les contrôles. Un relevé déposé ne se modifie pas : on le remplace.</p>

        <section data-statements>
            <?php if ($year === null): ?>
                <p class="rb-admin-note">Aucun exercice pour l'instant : créez-le d'abord dans la comptabilité.</p>
            <?php else: ?>
                <?php if (count($page['years']) > 1): ?>
                    <nav class="rb-year-switch" aria-label="Exercices">
                        <?php foreach ($page['years'] as $choice): ?>
                            <a href="<?= e($choice['url']) ?>" class="rb-year-link"<?= $choice['selected'] ? ' aria-current="page"' : '' ?>><?= e($choice['label']) ?></a>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>
                <h2><?= e($year['label']) ?></h2>

                <?php if ($year['isOpen']): ?>
                    <?php require __DIR__ . '/_statement-form.php'; ?>
                <?php else: ?>
                    <p class="rb-admin-note">Cet exercice est clos : ses relevés ne changent plus.</p>
                <?php endif; ?>

                <?php if ($page['statements'] === []): ?>
                    <p class="rb-admin-note">Aucun relevé déposé pour cet exercice.</p>
                <?php else: ?>
                    <ul class="rb-entry-list">
                        <?php foreach ($page['statements'] as $statement): ?>
                            <li class="rb-card rb-statement" data-statement>
                                <div class="rb-entry-head">
                                    <span class="rb-entry-date"><?= e($statement['period']) ?></span>
                                    <?php if ($statement['isReplaced']): ?><span class="rb-badge rb-badge-warn">Remplacé</span><?php endif; ?>
                                </div>
                                <p class="rb-entry-label"><?= e($statement['name']) ?> <span class="rb-entry-note">(<?= e($statement['size']) ?>, déposé le <?= e($statement['uploadedOn']) ?>)</span></p>
                                <dl class="rb-ledger-figures">
                                    <div><dt>Solde du relevé</dt><dd><?= $statement['balance'] === '' ? 'Non saisi' : e($statement['balance']) ?></dd></div>
                                    <div><dt>Solde selon le livre</dt><dd><?= e($statement['ledger']) ?></dd></div>
                                </dl>
                                <?php if ($statement['gapState'] === 'ok'): ?>
                                    <p class="rb-statement-gap rb-statement-gap-ok">Le livre et le relevé concordent.</p>
                                <?php elseif ($statement['gapState'] === 'gap'): ?>
                                    <p class="rb-statement-gap rb-statement-gap-warn">Écart de <?= e($statement['gap']) ?> entre le relevé et le livre : une écriture manque ou est en trop.</p>
                                <?php endif; ?>
                                <div class="rb-entry-actions">
                                    <a class="rb-btn" href="<?= e($statement['downloadUrl']) ?>">Télécharger</a>
                                    <?php if ($statement['canReplace']): ?>
                                        <button type="button" class="rb-btn" data-action="replace-statement" data-statement-id="<?= e((string) $statement['id']) ?>">Remplacer</button>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>

    <?php require __DIR__ . '/../../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
