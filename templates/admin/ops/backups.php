<?php
/**
 * Exploitation, onglet Sauvegardes (#375). Rendu par le serveur, sans JavaScript, lecture seule. Le contrôleur ne transmet que des
 * valeurs déjà mises en forme : jamais un chemin du serveur ni une clé.
 *
 * @var array<string, \App\Metrics\Report\StatusCard> $dumpCards
 * @var array<string, \App\Metrics\Report\StatusCard> $protectionCards
 * @var string $trendChart SVG produit par SvgChart, vide s'il n'y a pas de dump
 * @var list<\App\Backup\Restore\BackupEntry> $recent
 * @var \DateTimeZone $localTimezone
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Exploitation — Sauvegardes — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
    <link rel="stylesheet" href="/assets/css/pages/metrics.css">
</head>
<body>
    <div class="rb-admin-page rb-metrics-page">
        <?php $section = 'backups'; require __DIR__ . '/_header.php'; ?>

        <section aria-labelledby="ops-dumps-title">
            <h2 id="ops-dumps-title" class="rb-metrics-section">Dumps de la base</h2>
            <?php $cards = $dumpCards; require __DIR__ . '/../metrics/_cards.php'; ?>
        </section>

        <section aria-labelledby="ops-protection-title">
            <h2 id="ops-protection-title" class="rb-metrics-section">Protection et tâches planifiées</h2>
            <?php $cards = $protectionCards; require __DIR__ . '/../metrics/_cards.php'; ?>
        </section>

        <?php if ($trendChart !== ''): ?>
            <section aria-labelledby="ops-trend-title">
                <h2 id="ops-trend-title" class="rb-metrics-section">Taille des dumps quotidiens</h2>
                <p class="rb-metrics-card-hint">Une chute brutale signale un dump tronqué.</p>
                <div class="rb-metrics-charts"><?= $trendChart /* SVG produit par SvgChart : tout le texte y est déjà échappé */ ?></div>
            </section>
        <?php endif; ?>

        <section aria-labelledby="ops-recent-title">
            <h2 id="ops-recent-title" class="rb-metrics-section">Derniers dumps</h2>
            <?php if ($recent === []): ?>
                <p>Aucun dump dans le dossier des sauvegardes.</p>
            <?php else: ?>
                <div class="rb-admin-table-wrapper">
                    <table class="rb-admin-table">
                        <thead><tr><th scope="col">Date et heure</th><th scope="col">Type</th><th scope="col">Taille</th></tr></thead>
                        <tbody>
                            <?php foreach ($recent as $backup): ?>
                                <tr>
                                    <td><?= e($backup->createdAt->setTimezone($localTimezone)->format('d/m/Y à H:i')) ?></td>
                                    <td><?= $backup->kind === 'daily' ? 'Quotidien' : 'Avant déploiement' ?></td>
                                    <td><?= e(\App\Metrics\Report\HumanSize::of($backup->bytes)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <p><a href="/admin/restore">Restaurer une sauvegarde</a></p>
        </section>
    </div>

    <?php require __DIR__ . '/../../partials/nav.php'; ?>
</body>
</html>
