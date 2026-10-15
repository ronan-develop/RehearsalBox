<?php
/**
 * Compte rendu d'AG imprimable (#361). Page autonome à fond blanc, sans onglets d'administration : le bouton « Imprimer » et le
 * retour sont masqués à l'impression (.no-print). Aucun script ni style en ligne (CSP) : l'impression passe par le bouton data-print.
 *
 * @var array{
 *     title: string,
 *     associationName: string,
 *     statusBanner: ?string,
 *     versionLine: ?string,
 *     opening: string,
 *     quorum: ?string,
 *     board: list<array{label: string, name: string}>,
 *     counts: string,
 *     present: list<string>,
 *     represented: list<string>,
 *     excused: list<string>,
 *     agenda: list<array{number: int, title: string, discussion: ?string}>,
 *     resolutions: list<array{number: int, text: string, votes: string, outcome: ?string}>,
 *     noResolution: ?string,
 *     moralReport: ?string,
 *     financialReport: ?string,
 *     otherBusiness: ?string,
 *     closing: string,
 *     signatures: list<array{label: string, name: string}>
 * } $minutes
 * @var string $backHref adresse de la page parente (chemin du site)
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($minutes['title']) ?> — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/assembly-print.css">
</head>
<body>
    <main class="rb-print-page">
        <div class="rb-print-controls no-print">
            <?php $backLabel = 'Retour au compte rendu'; require __DIR__ . '/../../partials/back-button.php'; ?>
            <button type="button" class="rb-btn rb-btn-primary" data-print>Imprimer</button>
        </div>

        <?php if ($minutes['statusBanner'] !== null): ?>
            <p class="rb-print-banner"><?= e($minutes['statusBanner']) ?></p>
        <?php endif; ?>

        <p class="rb-print-association"><?= e($minutes['associationName']) ?></p>
        <h1 class="rb-print-title"><?= e($minutes['title']) ?></h1>
        <?php if ($minutes['versionLine'] !== null): ?>
            <p class="rb-print-version"><?= e($minutes['versionLine']) ?></p>
        <?php endif; ?>

        <section class="rb-print-section">
            <p><?= nl2br(e($minutes['opening'])) ?></p>
        </section>

        <section class="rb-print-section">
            <h2>Bureau</h2>
            <ul class="rb-print-board">
                <?php foreach ($minutes['board'] as $member): ?>
                    <li><?= e($member['label']) ?> : <?= e($member['name']) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="rb-print-section">
            <h2>Présences</h2>
            <p><?= e($minutes['counts']) ?></p>
            <?php foreach (['Présents' => $minutes['present'], 'Représentés' => $minutes['represented'], 'Excusés' => $minutes['excused']] as $label => $names): ?>
                <?php if ($names !== []): ?>
                    <p class="rb-print-attendees"><strong><?= e($label) ?> :</strong> <?= e(implode(', ', $names)) ?></p>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($minutes['quorum'] !== null): ?>
                <p class="rb-print-quorum"><?= nl2br(e($minutes['quorum'])) ?></p>
            <?php endif; ?>
        </section>

        <section class="rb-print-section">
            <h2>Ordre du jour</h2>
            <?php foreach ($minutes['agenda'] as $item): ?>
                <div class="rb-print-item">
                    <h3><?= e((string) $item['number']) ?>. <?= e($item['title']) ?></h3>
                    <?php if ($item['discussion'] !== null): ?>
                        <p><?= nl2br(e($item['discussion'])) ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="rb-print-section">
            <h2>Résolutions</h2>
            <?php if ($minutes['noResolution'] !== null): ?>
                <p><?= nl2br(e($minutes['noResolution'])) ?></p>
            <?php endif; ?>
            <?php foreach ($minutes['resolutions'] as $resolution): ?>
                <div class="rb-print-resolution">
                    <h3>Résolution <?= e((string) $resolution['number']) ?></h3>
                    <p><?= nl2br(e($resolution['text'])) ?></p>
                    <p class="rb-print-votes">Votes : <?= e($resolution['votes']) ?></p>
                    <?php if ($resolution['outcome'] !== null): ?>
                        <p class="rb-print-outcome">Résultat : <?= e($resolution['outcome']) ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>

        <?php if ($minutes['moralReport'] !== null): ?>
            <section class="rb-print-section">
                <h2>Rapport moral</h2>
                <p><?= nl2br(e($minutes['moralReport'])) ?></p>
            </section>
        <?php endif; ?>

        <?php if ($minutes['financialReport'] !== null): ?>
            <section class="rb-print-section">
                <h2>Rapport financier</h2>
                <p><?= nl2br(e($minutes['financialReport'])) ?></p>
            </section>
        <?php endif; ?>

        <?php if ($minutes['otherBusiness'] !== null): ?>
            <section class="rb-print-section">
                <h2>Questions diverses</h2>
                <p><?= nl2br(e($minutes['otherBusiness'])) ?></p>
            </section>
        <?php endif; ?>

        <section class="rb-print-section">
            <p><?= nl2br(e($minutes['closing'])) ?></p>
        </section>

        <section class="rb-print-signatures">
            <?php foreach ($minutes['signatures'] as $signature): ?>
                <div class="rb-print-signature">
                    <p><strong><?= e($signature['label']) ?></strong></p>
                    <p><?= e($signature['name']) ?></p>
                    <p class="rb-print-signature-line">Signature :</p>
                </div>
            <?php endforeach; ?>
        </section>
    </main>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
