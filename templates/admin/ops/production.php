<?php
/**
 * Exploitation, onglet Production (#375) : release servie, dernier déploiement, migrations, retour arrière, historique. Rendu par le
 * serveur, sans JavaScript, lecture seule. Le contrôleur ne transmet que des valeurs lues avec un motif strict : jamais un chemin.
 *
 * @var array<string, \App\Metrics\Report\StatusCard> $cards
 * @var string $durationChart SVG produit par SvgChart, vide s'il n'y a pas de déploiement journalisé
 * @var list<array{id: string, commit: string, at: ?\DateTimeImmutable, current: bool, rollbackTarget: bool}> $releases
 * @var list<\App\Operations\Production\Deployment> $deployments
 * @var \DateTimeZone $localTimezone
 */
$checkLabels = ['ci' => 'CI verte', 'local' => 'tests locaux', 'skipped' => 'sautés'];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Exploitation — Production — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
    <link rel="stylesheet" href="/assets/css/pages/metrics.css">
</head>
<body>
    <div class="rb-admin-page rb-metrics-page">
        <?php $section = 'production'; require __DIR__ . '/_header.php'; ?>

        <section aria-labelledby="ops-prod-title">
            <h2 id="ops-prod-title" class="rb-metrics-section">Production</h2>
            <?php require __DIR__ . '/../metrics/_cards.php'; ?>
        </section>

        <?php if ($durationChart !== ''): ?>
            <section aria-labelledby="ops-duration-title">
                <h2 id="ops-duration-title" class="rb-metrics-section">Durée des déploiements</h2>
                <div class="rb-metrics-charts"><?= $durationChart /* SVG produit par SvgChart : tout le texte y est déjà échappé */ ?></div>
            </section>
        <?php endif; ?>

        <section aria-labelledby="ops-releases-title">
            <h2 id="ops-releases-title" class="rb-metrics-section">Releases conservées</h2>
            <?php if ($releases === []): ?>
                <p>Aucune release trouvée.</p>
            <?php else: ?>
                <div class="rb-admin-table-wrapper">
                    <table class="rb-admin-table">
                        <thead><tr><th scope="col">Release</th><th scope="col">Date</th><th scope="col">Rôle</th></tr></thead>
                        <tbody>
                            <?php foreach ($releases as $release): ?>
                                <tr>
                                    <td><?= e($release['id']) ?></td>
                                    <td><?= $release['at'] === null ? '—' : e($release['at']->format('d/m/Y à H:i')) ?></td>
                                    <td><?= $release['current'] ? 'Servie' : ($release['rollbackTarget'] ? 'Cible du retour arrière' : '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section aria-labelledby="ops-deployments-title">
            <h2 id="ops-deployments-title" class="rb-metrics-section">Derniers déploiements</h2>
            <?php if ($deployments === []): ?>
                <p>Aucun déploiement journalisé : le journal se remplit à partir du prochain déploiement.</p>
            <?php else: ?>
                <p class="rb-metrics-card-hint">Dates à l'heure du poste de dev qui a lancé le déploiement.</p>
                <div class="rb-admin-table-wrapper">
                    <table class="rb-admin-table">
                        <thead><tr><th scope="col">Début</th><th scope="col">Release</th><th scope="col">Durée</th><th scope="col">Résultat</th><th scope="col">Contrôles</th></tr></thead>
                        <tbody>
                            <?php foreach ($deployments as $deployment): ?>
                                <tr>
                                    <td><?= e($deployment->startedAt->setTimezone($localTimezone)->format('d/m/Y à H:i')) ?></td>
                                    <td><?= e($deployment->release) ?></td>
                                    <td><?= e((string) $deployment->durationSeconds) ?> s</td>
                                    <td><?= $deployment->succeeded ? '✓ Réussi' : ($deployment->switched ? '✕ Basculé, contrôle échoué' : '⚠ Échoué avant la bascule') ?></td>
                                    <td><?= e($checkLabels[$deployment->checks] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <?php require __DIR__ . '/../../partials/nav.php'; ?>
</body>
</html>
