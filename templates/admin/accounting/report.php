<?php
/**
 * Rapport financier d'un exercice (#362), page imprimable à fond blanc pour l'assemblée générale (art. 11) : soldes, totaux par catégorie, journal.
 * Aucun script ni style en ligne (CSP) : l'impression passe par le bouton data-print.
 *
 * @var array<string, mixed> $report voir YearReportView::build
 * @var string $backHref
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($report['title']) ?> — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/assembly-print.css">
    <link rel="stylesheet" href="/assets/css/pages/accounting-print.css">
</head>
<body>
    <main class="rb-print-page">
        <div class="rb-print-controls no-print">
            <?php $backLabel = 'Retour à la comptabilité'; require __DIR__ . '/../../partials/back-button.php'; ?>
            <button type="button" class="rb-btn rb-btn-primary" data-print>Imprimer</button>
        </div>

        <?php if ($report['banner'] !== null): ?>
            <p class="rb-print-banner"><?= e($report['banner']) ?></p>
        <?php endif; ?>

        <?php if ($report['associationName'] !== ''): ?><p class="rb-print-association"><?= e($report['associationName']) ?></p><?php endif; ?>
        <h1 class="rb-print-title"><?= e($report['title']) ?></h1>
        <p class="rb-print-version"><?= e($report['period']) ?> — <?= e($report['statusLine']) ?></p>

        <section class="rb-print-section">
            <h2>Soldes</h2>
            <table class="rb-report-table">
                <tbody>
                    <tr><th scope="row">Solde d'ouverture</th><td><?= e($report['opening']) ?></td></tr>
                    <tr><th scope="row">Recettes</th><td><?= e($report['recettes']) ?></td></tr>
                    <tr><th scope="row">Dépenses</th><td><?= e($report['depenses']) ?></td></tr>
                    <tr class="rb-report-total"><th scope="row"><?= e($report['closingLabel']) ?></th><td><?= e($report['closing']) ?></td></tr>
                </tbody>
            </table>
        </section>

        <section class="rb-print-section">
            <h2>Recettes par catégorie</h2>
            <?php if ($report['recetteLines'] === []): ?>
                <p>Aucune recette.</p>
            <?php else: ?>
                <table class="rb-report-table"><tbody>
                    <?php foreach ($report['recetteLines'] as $line): ?><tr><th scope="row"><?= e($line['label']) ?></th><td><?= e($line['amount']) ?></td></tr><?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
            <h2>Dépenses par catégorie</h2>
            <?php if ($report['depenseLines'] === []): ?>
                <p>Aucune dépense.</p>
            <?php else: ?>
                <table class="rb-report-table"><tbody>
                    <?php foreach ($report['depenseLines'] as $line): ?><tr><th scope="row"><?= e($line['label']) ?></th><td><?= e($line['amount']) ?></td></tr><?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </section>

        <section class="rb-print-section">
            <h2>Journal</h2>
            <?php if ($report['entries'] === []): ?>
                <p>Aucune écriture.</p>
            <?php else: ?>
                <table class="rb-report-table rb-report-journal">
                    <thead><tr><th scope="col">N°</th><th scope="col">Date</th><th scope="col">Libellé</th><th scope="col">Montant</th></tr></thead>
                    <tbody>
                        <?php foreach ($report['entries'] as $entry): ?>
                            <tr>
                                <td><?= e((string) $entry['number']) ?></td>
                                <td><?= e($entry['date']) ?></td>
                                <td><?= e($entry['label']) ?> <span class="rb-report-meta">(<?= e($entry['category']) ?><?= $entry['detail'] !== '' ? ', ' . e($entry['detail']) : '' ?>)</span></td>
                                <td><?= e($entry['amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
