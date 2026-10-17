<?php
/**
 * En-tête de l'exploitation (#375) : titre, onglets et liens vers les pages voisines (mesures, restauration).
 *
 * @var string $section 'backups' ou 'production'
 * @var bool|null $showRestoreLink onglet « Restauration » : propriétaire seulement (OpsLinkRenderer)
 */
$sections = ['backups' => ['/admin/ops', 'Sauvegardes'], 'production' => ['/admin/ops/production', 'Production']];
?>
<?php $backHref = '/'; $backLabel = 'Retour aux disponibilités'; require __DIR__ . '/../../partials/back-button.php'; ?>
<h1>Exploitation</h1>
<p class="rb-admin-subtitle">État de la production, en lecture seule. Aucun chemin du serveur, aucune clé ni donnée personnelle n'y est affiché.</p>

<nav class="rb-admin-tabs" aria-label="Sections de l'exploitation">
    <?php foreach ($sections as $key => [$href, $label]): ?>
        <a href="<?= e($href) ?>" class="rb-admin-tab"<?= $key === $section ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
    <a href="/admin/metrics" class="rb-admin-tab">Mesures</a>
    <?php if (!empty($showRestoreLink)): ?><a href="/admin/restore" class="rb-admin-tab">Restauration</a><?php endif; ?>
</nav>
