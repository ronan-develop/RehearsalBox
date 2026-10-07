<?php
/**
 * Tableau de bord des mesures : sécurité et anomalies (#197). Rendu par le serveur, sans JavaScript. On rapporte, on ne bloque
 * rien : les adresses n'apparaissent jamais, seulement une empreinte tronquée.
 *
 * @var \App\Metrics\Report\Security\SecurityReport $report
 * @var list<\App\Metrics\Report\MetricsPeriod> $periods
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Mesures : sécurité — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
    <link rel="stylesheet" href="/assets/css/pages/metrics.css">
</head>
<body>
    <div class="rb-admin-page rb-metrics-page">
        <?php $section = 'security'; $period = $report->period; require __DIR__ . '/_header.php'; ?>

        <section aria-labelledby="metrics-cards-title">
            <h2 id="metrics-cards-title" class="rb-metrics-section">Compteurs</h2>
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

        <section aria-labelledby="metrics-anomalies-title">
            <h2 id="metrics-anomalies-title" class="rb-metrics-section">Anomalies récentes</h2>
            <?php if ($report->anomalies === []): ?>
                <p class="rb-admin-note">Aucune anomalie détectée sur la période.</p>
            <?php else: ?>
                <div class="rb-admin-table-wrapper">
                    <table class="rb-metrics-anomalies">
                        <thead><tr><th scope="col">Type</th><th scope="col">Empreinte</th><th scope="col">Évènements</th><th scope="col">Dernier</th></tr></thead>
                        <tbody>
                        <?php foreach ($report->anomalies as $anomaly): ?>
                            <tr>
                                <td><?= e($anomaly->kind->label()) ?></td>
                                <td><code><?= e($anomaly->fingerprint) ?></code></td>
                                <td><?= e((string) $anomaly->count) ?></td>
                                <td><?= e($anomaly->lastAt->setTimezone($localTimezone)->format('d/m H:i')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="rb-metrics-card-hint">Rapport seulement : aucune adresse n'est bloquée automatiquement.</p>
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
