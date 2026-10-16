<?php
/**
 * Panneau « Nouvelle écriture » (replié par défaut) : écritures rapides, puis le formulaire. Enregistrement par l'API (JS).
 *
 * @var array<string, mixed> $page
 * @var array<string, mixed> $year
 */
$form = $page['form'];
?>
<div class="rb-ledger-new">
    <button type="button" class="rb-btn rb-btn-primary" data-action="new-entry">Nouvelle écriture</button>

    <form class="rb-card rb-entry-form" data-entry-form data-year-id="<?= e((string) $year['id']) ?>" hidden>
        <h2>Nouvelle écriture</h2>
        <div class="rb-entry-presets" role="group" aria-label="Écritures rapides">
            <span class="rb-entry-presets-title">Écritures rapides</span>
            <button type="button" class="rb-btn" data-preset="cotisation">Cotisation</button>
            <button type="button" class="rb-btn" data-preset="droit-entree">Droit d'entrée</button>
            <button type="button" class="rb-btn" data-preset="loyer">Loyer</button>
            <button type="button" class="rb-btn" data-preset="electricite">Électricité</button>
            <button type="button" class="rb-btn" data-preset="assurance">Assurance</button>
            <button type="button" class="rb-btn" data-preset="frais-bancaires">Frais bancaires</button>
        </div>
        <div class="rb-field">
            <label for="entry-direction">Type</label>
            <select id="entry-direction" name="direction" class="rb-input" required>
                <?php foreach ($form['directions'] as $option): ?>
                    <option value="<?= e($option['value']) ?>"><?= e($option['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="rb-field-error" role="alert" data-field-error="direction" hidden></p>
        </div>
        <div class="rb-field">
            <label for="entry-category">Catégorie</label>
            <select id="entry-category" name="category" class="rb-input" required data-categories="<?= e((string) json_encode($form['categories'], JSON_UNESCAPED_UNICODE)) ?>">
                <?php foreach ($form['categories'] as $option): ?>
                    <option value="<?= e($option['value']) ?>" data-direction="<?= e($option['direction']) ?>"><?= e($option['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="rb-field-error" role="alert" data-field-error="category" hidden></p>
        </div>
        <div class="rb-entry-dues" data-dues-fields hidden>
            <div class="rb-field">
                <label for="entry-member">Adhérent</label>
                <select id="entry-member" name="memberUserId" class="rb-input">
                    <option value="">Choisir l'adhérent</option>
                    <?php foreach ($form['members'] as $option): ?>
                        <option value="<?= e($option['value']) ?>"><?= e($option['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="rb-field-error" role="alert" data-field-error="memberUserId" hidden></p>
            </div>
            <div class="rb-field">
                <label for="entry-month">Mois couvert</label>
                <input type="month" id="entry-month" name="periodMonth" class="rb-input" value="<?= e($form['month']) ?>">
                <p class="rb-field-error" role="alert" data-field-error="periodMonth" hidden></p>
            </div>
        </div>
        <div class="rb-field">
            <label for="entry-date">Date</label>
            <input type="date" id="entry-date" name="occurredOn" class="rb-input" required>
            <p class="rb-field-error" role="alert" data-field-error="occurredOn" hidden></p>
        </div>
        <div class="rb-field">
            <label for="entry-label">Libellé</label>
            <input type="text" id="entry-label" name="label" class="rb-input" maxlength="150" required autocomplete="off">
            <p class="rb-field-error" role="alert" data-field-error="label" hidden></p>
        </div>
        <div class="rb-field">
            <label for="entry-amount">Montant (en euros)</label>
            <input type="text" id="entry-amount" name="amount" class="rb-input" inputmode="decimal" placeholder="12,50" autocomplete="off" required>
            <p class="rb-field-error" role="alert" data-field-error="amount" hidden></p>
        </div>
        <div class="rb-field">
            <label for="entry-method">Moyen de paiement</label>
            <select id="entry-method" name="paymentMethod" class="rb-input" required>
                <?php foreach ($form['methods'] as $option): ?>
                    <option value="<?= e($option['value']) ?>"><?= e($option['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="rb-field-error" role="alert" data-field-error="paymentMethod" hidden></p>
        </div>
        <div class="rb-field">
            <label for="entry-voucher">Pièce justificative <span class="rb-field-hint" data-voucher-hint>(obligatoire pour une dépense)</span></label>
            <input type="text" id="entry-voucher" name="voucherRef" class="rb-input" maxlength="100" placeholder="N° de facture, de ticket…" autocomplete="off">
            <p class="rb-field-error" role="alert" data-field-error="voucherRef" hidden></p>
        </div>
        <div class="rb-field">
            <label for="entry-note">Remarque (facultatif)</label>
            <input type="text" id="entry-note" name="note" class="rb-input" maxlength="255" autocomplete="off">
            <p class="rb-field-error" role="alert" data-field-error="note" hidden></p>
        </div>
        <p class="rb-field-error" role="alert" data-form-error hidden></p>
        <div class="rb-entry-actions">
            <button type="submit" class="rb-btn rb-btn-primary">Enregistrer l'écriture</button>
            <button type="button" class="rb-btn" data-action="cancel-entry">Annuler</button>
        </div>
    </form>
</div>
