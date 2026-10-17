<?php
/**
 * Comptabilité de l'association (#362) : exercice choisi, soldes, panneau de saisie et journal. Rendu serveur : les écritures passent
 * par l'API (JS de la page). Tout texte est échappé ici ; aucun style ni script en ligne.
 *
 * @var string $csrfToken
 * @var array<string, mixed> $page voir AccountingJournalView::page
 * @var array<string, mixed>|null $todo voir TodoView::build
 * @var string|null $reminderProblem pourquoi les rappels activés ne partent pas (poste de trésorier vide, compte désactivé)
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
    <title>Admin — Comptabilité — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
    <link rel="stylesheet" href="/assets/css/pages/accounting.css">
</head>
<body>
    <div class="rb-admin-page">
        <?php $backHref = '/'; $backLabel = 'Retour aux disponibilités'; require __DIR__ . '/../../partials/back-button.php'; ?>
        <?php require __DIR__ . '/../../partials/admin-tabs.php'; ?>
        <h1>Comptabilité</h1>
        <p class="rb-admin-subtitle">Livre de comptes de l'association : l'argent reçu et l'argent dépensé, exercice par exercice.</p>

        <section data-accounting>
            <?php if (($reminderProblem ?? null) !== null): ?>
                <p class="rb-admin-note rb-todo-banner" role="status"><?= e($reminderProblem) ?> <a href="/admin/board">Régler le bureau</a></p>
            <?php endif; ?>
            <?php if ($year === null): ?>
                <p class="rb-admin-note">Aucun exercice pour l'instant : créez le premier pour commencer à noter les écritures.</p>
            <?php else: ?>
                <?php if (count($page['years']) > 1): ?>
                    <nav class="rb-year-switch" aria-label="Exercices">
                        <?php foreach ($page['years'] as $choice): ?>
                            <a href="<?= e($choice['url']) ?>" class="rb-year-link"<?= $choice['selected'] ? ' aria-current="page"' : '' ?>><?= e($choice['label']) ?></a>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>

                <?php if ($todo !== null): ?><?php require __DIR__ . '/_todo.php'; ?><?php endif; ?>

                <div class="rb-card rb-ledger-summary">
                    <h2><?= e($year['label']) ?> <span class="rb-badge"><?= e($year['status']) ?></span></h2>
                    <p class="rb-admin-note"><?= e($year['period']) ?></p>
                    <dl class="rb-ledger-figures">
                        <div><dt>Solde d'ouverture</dt><dd><?= e($year['opening']) ?></dd></div>
                        <div><dt>Argent reçu</dt><dd class="rb-amount-recette"><?= e($year['recettes']) ?></dd></div>
                        <div><dt>Argent dépensé</dt><dd class="rb-amount-depense"><?= e($year['depenses']) ?></dd></div>
                        <div><dt>Solde actuel</dt><dd class="rb-ledger-balance<?= $year['balanceNegative'] ? ' rb-amount-depense' : '' ?>"><?= e($year['balance']) ?></dd></div>
                    </dl>
                </div>

                <nav class="rb-ledger-links" aria-label="Outils de la comptabilité">
                    <a class="rb-btn" href="/admin/accounting/statements?year=<?= e((string) $year['id']) ?>">Relevés de compte</a>
                    <a class="rb-btn" href="/admin/accounting/recurring">Écritures récurrentes</a>
                    <a class="rb-btn" href="/admin/accounting/cotisations?year=<?= e((string) $year['id']) ?>">Cotisations</a>
                    <a class="rb-btn" href="/admin/accounting/years/<?= e((string) $year['id']) ?>/report">Rapport imprimable</a>
                    <a class="rb-btn" href="/admin/accounting/years/<?= e((string) $year['id']) ?>/export.csv" download>Exporter en CSV</a>
                    <a class="rb-btn" href="/admin/texts">Mode d'emploi du trésorier</a>
                </nav>

                <?php require __DIR__ . '/_year-actions.php'; ?>

                <?php if ($year['isOpen']): ?>
                    <?php require __DIR__ . '/_entry-form.php'; ?>
                <?php endif; ?>

                <?php require __DIR__ . '/_filters.php'; ?>

                <?php if ($page['entries'] === []): ?>
                    <p class="rb-admin-note"><?= $page['filters']['active'] ? 'Aucune écriture ne correspond à ces filtres.' : 'Aucune écriture dans cet exercice.' ?></p>
                <?php else: ?>
                    <ul class="rb-entry-list" data-entry-list>
                        <?php foreach ($page['entries'] as $entry): ?>
                            <?php require __DIR__ . '/_entry-card.php'; ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>

            <?php require __DIR__ . '/_year-form.php'; ?>
        </section>
    </div>

    <?php require __DIR__ . '/../../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
