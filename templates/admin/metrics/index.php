<?php
/**
 * Tableau de bord des mesures : santé et e-mails (#196). Rendu par le serveur, sans JavaScript : la période se choisit par un
 * lien, les graphiques sont des SVG déjà rendus (accessibles, avec leur tableau de valeurs).
 *
 * @var \App\Metrics\Report\HealthReport $report
 * @var list<\App\Metrics\Report\MetricsPeriod> $periods
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Mesures — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
    <link rel="stylesheet" href="/assets/css/pages/metrics.css">
</head>
<body>
    <div class="rb-admin-page rb-metrics-page">
        <?php $section = 'health'; $period = $report->period; require __DIR__ . '/_header.php'; ?>

        <section aria-labelledby="metrics-cards-title">
            <h2 id="metrics-cards-title" class="rb-metrics-section">État</h2>
            <?php $cards = $report->cards; require __DIR__ . '/_cards.php'; ?>
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
