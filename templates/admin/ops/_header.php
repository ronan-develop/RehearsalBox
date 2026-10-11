<?php
/**
 * En-tête de l'exploitation (#375) : titre, onglets et liens vers les pages voisines (mesures, restauration).
 *
 * @var string $section 'backups'
 */
$sections = ['backups' => ['/admin/ops', 'Sauvegardes']];
?>
<a href="/" class="rb-back-link" aria-label="Retour aux disponibilités"><?php require __DIR__ . '/../../partials/icon-back.php'; ?></a>
<h1>Exploitation</h1>
<p class="rb-admin-subtitle">État de la production, en lecture seule. Aucun chemin du serveur, aucune clé ni donnée personnelle n'y est affiché.</p>

<nav class="rb-admin-tabs" aria-label="Sections de l'exploitation">
    <?php foreach ($sections as $key => [$href, $label]): ?>
        <a href="<?= e($href) ?>" class="rb-admin-tab"<?= $key === $section ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
    <a href="/admin/metrics" class="rb-admin-tab">Mesures</a>
    <a href="/admin/restore" class="rb-admin-tab">Restauration</a>
</nav>
