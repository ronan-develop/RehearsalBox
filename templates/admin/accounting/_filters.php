<?php
/**
 * Filtres du journal : navigation (GET), aucune écriture. Les valeurs viennent de listes blanches.
 *
 * @var array<string, mixed> $page
 * @var array<string, mixed> $year
 */
$filters = $page['filters'];
?>
<form class="rb-ledger-filters" method="get" action="/admin/accounting">
    <input type="hidden" name="year" value="<?= e((string) $year['id']) ?>">
    <div class="rb-field">
        <label for="filter-direction">Sens</label>
        <select id="filter-direction" name="direction" class="rb-input">
            <option value="">Tous</option>
            <?php foreach ($filters['directions'] as $option): ?>
                <option value="<?= e($option['value']) ?>"<?= $filters['direction'] === $option['value'] ? ' selected' : '' ?>><?= e($option['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="rb-field">
        <label for="filter-category">Catégorie</label>
        <select id="filter-category" name="category" class="rb-input">
            <option value="">Toutes</option>
            <?php foreach ($filters['categories'] as $option): ?>
                <option value="<?= e($option['value']) ?>"<?= $filters['category'] === $option['value'] ? ' selected' : '' ?>><?= e($option['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="rb-ledger-filter-actions">
        <button type="submit" class="rb-btn">Filtrer</button>
        <?php if ($filters['active']): ?><a href="/admin/accounting?year=<?= e((string) $year['id']) ?>" class="rb-btn">Tout afficher</a><?php endif; ?>
    </div>
</form>
