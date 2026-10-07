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
        <h1>Mesures</h1>
        <p class="rb-admin-subtitle">Santé du site et e-mails. Aucune donnée personnelle n'est conservée dans ces mesures.</p>

        <nav class="rb-metrics-periods" aria-label="Période">
            <?php foreach ($periods as $period): ?>
                <a href="/admin/metrics?periode=<?= e($period->value) ?>" class="rb-metrics-period"<?= $period === $report->period ? ' aria-current="page"' : '' ?>><?= e($period->label()) ?></a>
            <?php endforeach; ?>
        </nav>

        <section aria-labelledby="metrics-cards-title">
            <h2 id="metrics-cards-title" class="rb-metrics-section">État</h2>
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
