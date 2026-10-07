<?php
/**
 * Tableau de bord des mesures : charge et dégradation par rapport à l'usage (#198). Rendu par le serveur, sans JavaScript.
 *
 * @var \App\Metrics\Report\Load\LoadReport $report
 * @var list<\App\Metrics\Report\MetricsPeriod> $periods
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Mesures : charge — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
    <link rel="stylesheet" href="/assets/css/pages/metrics.css">
</head>
<body>
    <div class="rb-admin-page rb-metrics-page">
        <?php $section = 'load'; $period = $report->period; require __DIR__ . '/_header.php'; ?>

        <section aria-labelledby="metrics-cards-title">
            <h2 id="metrics-cards-title" class="rb-metrics-section">Charge</h2>
            <div class="rb-metrics-cards">
                <?php foreach ($report->cards as $card): ?>
                    <div class="rb-metrics-card"<?= $card->status === null ? '' : ' data-status="' . e($card->status->value) . '"' ?>>
                        <p class="rb-metrics-card-label"><?= e($card->label) ?></p>
                        <p class="rb-metrics-card-value"><?= e($card->value) ?></p>
                        <?php if ($card->status !== null): ?>
                            <p class="rb-metrics-card-status"><span aria-hidden="true"><?= e($card->status->icon()) ?></span> <?= e($card->status->label()) ?></p>
                        <?php endif; ?>
                        <?php if ($card->hint !== ''): ?>
                            <p class="rb-metrics-card-hint"><?= e($card->hint) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <ul class="rb-metrics-verdict">
                <?php foreach ($report->degradation->messages as $message): ?>
                    <li><?= e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section aria-labelledby="metrics-routes-title">
            <h2 id="metrics-routes-title" class="rb-metrics-section">Routes les plus sollicitées</h2>
            <?php if ($report->routes === []): ?>
                <p class="rb-admin-note">Aucune requête mesurée sur la période.</p>
            <?php else: ?>
                <div class="rb-admin-table-wrapper">
                    <table class="rb-metrics-anomalies">
                        <thead><tr><th scope="col">Route</th><th scope="col">Requêtes</th><th scope="col">Moyenne</th><th scope="col">Maximum</th><th scope="col">Part du temps</th></tr></thead>
                        <tbody>
                        <?php foreach ($report->routes as $route): ?>
                            <tr>
                                <td><code><?= e($route['route']) ?></code><?= $route['polling'] ? ' <span class="rb-badge rb-badge-warn">polling</span>' : '' ?></td>
                                <td><?= e(number_format($route['requests'], 0, ',', ' ')) ?></td>
                                <td><?= e((string) $route['averageMs']) ?> ms</td>
                                <td><?= e((string) $route['maxMs']) ?> ms</td>
                                <td><?= e((string) $route['timeShare']) ?> %</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section aria-labelledby="metrics-charts-title">
            <h2 id="metrics-charts-title" class="rb-metrics-section">Sur <?= e($report->period->label()) ?></h2>
            <div class="rb-metrics-charts">
                <?php foreach ($report->charts as $chart): ?>
                    <?= $chart /* SVG produit par SvgChart : tout le texte y est déjà échappé */ ?>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <?php require __DIR__ . '/../../partials/nav.php'; ?>
</body>
</html>
