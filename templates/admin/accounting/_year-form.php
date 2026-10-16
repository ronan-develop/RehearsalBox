<?php
/**
 * Création d'un exercice : proposée sans exercice (le premier, avec le solde d'ouverture) ou quand le dernier est clôturé (le report est
 * alors calculé). Enregistrement par l'API (JS).
 *
 * @var array<string, mixed> $page
 */
$next = $page['next'];
if (!$next['canCreate']) {
    return;
}
$open = $page['year'] === null;
?>
<div class="rb-ledger-new">
    <?php if (!$open): ?><button type="button" class="rb-btn" data-action="new-year">Nouvel exercice</button><?php endif; ?>

    <form class="rb-card rb-year-form" data-year-form<?= $open ? '' : ' hidden' ?>>
        <h2><?= $next['isFirst'] ? 'Premier exercice' : 'Nouvel exercice' ?></h2>
        <div class="rb-field">
            <label for="year-label">Nom</label>
            <input type="text" id="year-label" name="label" class="rb-input" maxlength="60" value="<?= e($next['label']) ?>" placeholder="2025-2026" required autocomplete="off">
            <p class="rb-field-error" role="alert" data-field-error="label" hidden></p>
        </div>
        <div class="rb-field">
            <label for="year-start">Début</label>
            <input type="date" id="year-start" name="startsOn" class="rb-input" value="<?= e($next['startsOn']) ?>" required>
            <p class="rb-field-error" role="alert" data-field-error="startsOn" hidden></p>
        </div>
        <div class="rb-field">
            <label for="year-end">Fin</label>
            <input type="date" id="year-end" name="endsOn" class="rb-input" value="<?= e($next['endsOn']) ?>" required>
            <p class="rb-field-error" role="alert" data-field-error="endsOn" hidden></p>
        </div>
        <?php if ($next['isFirst']): ?>
            <div class="rb-field">
                <label for="year-opening">Solde d'ouverture (en euros)</label>
                <input type="text" id="year-opening" name="openingBalance" class="rb-input" inputmode="decimal" placeholder="0,00" autocomplete="off">
                <p class="rb-field-hint">Ce que le compte contient au premier jour de l'exercice (relevé de banque).</p>
                <p class="rb-field-error" role="alert" data-field-error="openingBalance" hidden></p>
            </div>
        <?php else: ?>
            <p class="rb-field-hint">Le solde d'ouverture est repris du solde final de l'exercice précédent.</p>
        <?php endif; ?>
        <p class="rb-field-error" role="alert" data-form-error hidden></p>
        <div class="rb-entry-actions">
            <button type="submit" class="rb-btn rb-btn-primary">Créer l'exercice</button>
            <?php if (!$open): ?><button type="button" class="rb-btn" data-action="cancel-year">Annuler</button><?php endif; ?>
        </div>
    </form>
</div>
