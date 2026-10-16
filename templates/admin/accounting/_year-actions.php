<?php
/**
 * Cycle de vie de l'exercice choisi : clôture (gèle les écritures, fixe le solde final) puis approbation par l'assemblée générale
 * (date de l'AG). Envois par l'API (JS) ; la révision protège d'une clôture sur un écran périmé.
 *
 * @var array<string, mixed> $year
 */
if (!$year['canClose'] && !$year['canApprove'] && $year['closing'] === '') {
    return;
}
?>
<div class="rb-card rb-year-actions" data-year-actions data-year-id="<?= e((string) $year['id']) ?>" data-revision="<?= e((string) $year['revision']) ?>">
    <?php if ($year['closing'] !== ''): ?>
        <p class="rb-admin-note">Solde final : <strong><?= e($year['closing']) ?></strong>, repris comme solde d'ouverture de l'exercice suivant.</p>
    <?php endif; ?>
    <?php if ($year['approvedOn'] !== ''): ?>
        <p class="rb-admin-note">Approuvé par l'assemblée générale du <?= e($year['approvedOn']) ?>. <a href="/admin/assemblies">Comptes rendus d'assemblée</a></p>
    <?php endif; ?>

    <?php if ($year['canClose']): ?>
        <p class="rb-admin-note">Clôturer fige les écritures de cet exercice : plus aucune saisie, et cela ne peut pas être défait.</p>
        <button type="button" class="rb-btn" data-action="close-year">Clôturer l'exercice</button>
        <form class="rb-entry-reverse" data-close-form hidden>
            <p>Confirmer la clôture de « <?= e($year['label']) ?> » ?</p>
            <p class="rb-field-error" role="alert" data-form-error hidden></p>
            <div class="rb-entry-actions">
                <button type="submit" class="rb-btn rb-btn-danger">Oui, clôturer</button>
                <button type="button" class="rb-btn" data-action="cancel-close">Garder ouvert</button>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($year['canApprove']): ?>
        <button type="button" class="rb-btn rb-btn-primary" data-action="approve-year">Enregistrer l'approbation</button>
        <form class="rb-entry-reverse" data-approve-form hidden>
            <div class="rb-field">
                <label for="approve-date">Date de l'assemblée générale qui a approuvé les comptes</label>
                <input type="date" id="approve-date" name="approvedOn" class="rb-input" required>
                <p class="rb-field-error" role="alert" data-field-error="approvedOn" hidden></p>
            </div>
            <p class="rb-field-error" role="alert" data-form-error hidden></p>
            <div class="rb-entry-actions">
                <button type="submit" class="rb-btn rb-btn-primary">Approuver</button>
                <button type="button" class="rb-btn" data-action="cancel-approve">Annuler</button>
            </div>
        </form>
    <?php endif; ?>
</div>
