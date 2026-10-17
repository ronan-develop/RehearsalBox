<?php
/**
 * Une ligne de la banque à relire : montant et date de la banque (non modifiables), puis ce que le trésorier confirme. Les propositions
 * (échéance d'un modèle, cotisation d'un adhérent, règle retenue) sont préremplies et dites en toutes lettres.
 *
 * @var array<string, mixed> $line
 * @var array<string, mixed> $page
 */
$key = (string) $line['id'];
?>
<li class="rb-card rb-entry rb-entry-<?= e($line['direction']) ?> rb-import-line" data-import-line data-line-id="<?= e($key) ?>" data-direction="<?= e($line['direction']) ?>" data-occurrence="<?= e($line['occurrence']) ?>">
    <label class="rb-import-check"><input type="checkbox" data-field="include"<?= $line['checked'] ? ' checked' : '' ?>> Valider cette ligne</label>
    <div class="rb-entry-head">
        <span class="rb-entry-date"><?= e($line['date']) ?></span>
        <span class="rb-entry-amount rb-amount-<?= e($line['direction']) ?>"><?= e($line['amount']) ?></span>
    </div>
    <p class="rb-entry-note">Libellé de la banque : <?= e($line['label']) ?></p>
    <?php if ($line['source'] !== ''): ?>
        <p class="rb-import-source"><span class="rb-badge">Proposé</span> <?= e($line['source']) ?></p>
    <?php else: ?>
        <p class="rb-import-source"><span class="rb-badge rb-badge-warn">À catégoriser</span> Choisissez une catégorie.</p>
    <?php endif; ?>

    <div class="rb-field">
        <label for="line-label-<?= e($key) ?>">Libellé de l'écriture</label>
        <input type="text" id="line-label-<?= e($key) ?>" class="rb-input" data-field="label" maxlength="150" value="<?= e($line['proposedLabel'] !== '' ? $line['proposedLabel'] : $line['label']) ?>">
        <p class="rb-field-error" role="alert" data-field-error="label" hidden></p>
    </div>
    <div class="rb-field">
        <label for="line-category-<?= e($key) ?>">Catégorie</label>
        <select id="line-category-<?= e($key) ?>" class="rb-input" data-field="category">
            <option value="">Choisir…</option>
            <?php foreach ($line['categories'] as $option): ?>
                <option value="<?= e($option['value']) ?>"<?= $line['category'] === $option['value'] ? ' selected' : '' ?>><?= e($option['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="rb-field-error" role="alert" data-field-error="category" hidden></p>
    </div>
    <div class="rb-field">
        <label for="line-method-<?= e($key) ?>">Moyen de paiement</label>
        <select id="line-method-<?= e($key) ?>" class="rb-input" data-field="paymentMethod">
            <?php foreach ($page['methods'] as $option): ?>
                <option value="<?= e($option['value']) ?>"<?= $line['method'] === $option['value'] ? ' selected' : '' ?>><?= e($option['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="rb-field-error" role="alert" data-field-error="paymentMethod" hidden></p>
    </div>
    <div class="rb-field">
        <label for="line-voucher-<?= e($key) ?>">Pièce justificative<?= $line['needsVoucher'] ? ' (obligatoire pour une dépense)' : ' (facultatif)' ?></label>
        <input type="text" id="line-voucher-<?= e($key) ?>" class="rb-input" data-field="voucherRef" maxlength="100" value="<?= e($line['voucher']) ?>">
        <p class="rb-field-error" role="alert" data-field-error="voucherRef" hidden></p>
    </div>
    <div class="rb-entry-dues" data-dues<?= $line['category'] === 'cotisations' && $line['memberUserId'] !== '' ? '' : ' hidden' ?> data-member="<?= e($line['memberUserId']) ?>" data-month="<?= e($line['periodMonth']) ?>">
        <div class="rb-field">
            <label for="line-member-<?= e($key) ?>">Adhérent</label>
            <select id="line-member-<?= e($key) ?>" class="rb-input" data-field="memberUserId"></select>
            <p class="rb-field-error" role="alert" data-field-error="memberUserId" hidden></p>
        </div>
        <div class="rb-field">
            <label for="line-month-<?= e($key) ?>">Mois couvert</label>
            <input type="month" id="line-month-<?= e($key) ?>" class="rb-input" data-field="periodMonth" value="<?= e($line['periodMonth']) ?>">
            <p class="rb-field-error" role="alert" data-field-error="periodMonth" hidden></p>
        </div>
    </div>
    <label class="rb-field-inline"><input type="checkbox" data-field="remember"> Retenir pour la prochaine fois (même libellé : même catégorie)</label>
    <p class="rb-field-error" role="alert" data-field-error="rulePattern" hidden></p>
    <p class="rb-field-error" role="alert" data-field-error="line" hidden></p>
    <p class="rb-field-error" role="alert" data-field-error="occurrence" hidden></p>
    <p class="rb-field-error" role="alert" data-field-error="occurredOn" hidden></p>
    <div class="rb-entry-actions">
        <button type="button" class="rb-btn" data-action="discard-line">Écarter cette ligne</button>
    </div>
</li>
