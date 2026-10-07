<?php
/**
 * En-tête commun des pages de mesures (#196, #197) : titre, onglets de section et choix de la période.
 *
 * @var string $section 'health' ou 'security'
 * @var \App\Metrics\Report\MetricsPeriod $period
 * @var list<\App\Metrics\Report\MetricsPeriod> $periods
 */
$sections = ['health' => ['/admin/metrics', 'Santé et e-mails'], 'security' => ['/admin/metrics/security', 'Sécurité']];
?>
<h1>Mesures</h1>
<p class="rb-admin-subtitle">Aucune donnée personnelle n'est conservée dans ces mesures (adresses : empreintes tronquées seulement).</p>

<nav class="rb-admin-tabs" aria-label="Sections des mesures">
    <?php foreach ($sections as $key => [$href, $label]): ?>
        <a href="<?= e($href) ?>?periode=<?= e($period->value) ?>" class="rb-admin-tab"<?= $key === $section ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<nav class="rb-metrics-periods" aria-label="Période">
    <?php foreach ($periods as $p): ?>
        <a href="<?= e($sections[$section][0]) ?>?periode=<?= e($p->value) ?>" class="rb-metrics-period"<?= $p === $period ? ' aria-current="page"' : '' ?>><?= e($p->label()) ?></a>
    <?php endforeach; ?>
</nav>
