<?php
/**
 * Une écriture du journal : numéro, date, libellé, montant signé, catégorie, moyen, pièce ; annulation si elle est permise.
 *
 * @var array<string, mixed> $entry
 */
?>
<li class="rb-card rb-entry rb-entry-<?= e($entry['direction']) ?>" data-entry data-entry-id="<?= e((string) $entry['id']) ?>">
    <div class="rb-entry-head">
        <span class="rb-entry-number">n° <?= e((string) $entry['number']) ?></span>
        <span class="rb-entry-date"><?= e($entry['date']) ?></span>
        <span class="rb-entry-amount rb-amount-<?= e($entry['direction']) ?>"><?= e($entry['amount']) ?></span>
    </div>
    <p class="rb-entry-label"><?= e($entry['label']) ?></p>
    <p class="rb-entry-meta">
        <span class="rb-badge"><?= e($entry['category']) ?></span>
        <span><?= e($entry['method']) ?></span>
        <?php if ($entry['isReversal']): ?><span class="rb-badge rb-badge-warn">Annulation</span><?php endif; ?>
        <?php if ($entry['isReversed']): ?><span class="rb-badge rb-badge-warn">Annulée</span><?php endif; ?>
    </p>
    <?php if ($entry['dues'] !== ''): ?><p class="rb-entry-note"><?= e($entry['dues']) ?></p><?php endif; ?>
    <?php if ($entry['voucher'] !== ''): ?><p class="rb-entry-note">Pièce : <?= e($entry['voucher']) ?></p><?php endif; ?>
    <?php if ($entry['note'] !== ''): ?><p class="rb-entry-note"><?= e($entry['note']) ?></p><?php endif; ?>
    <?php if ($entry['canReverse']): ?>
        <div class="rb-entry-actions">
            <button type="button" class="rb-btn" data-action="reverse" data-entry-id="<?= e((string) $entry['id']) ?>">Annuler cette écriture</button>
        </div>
        <form class="rb-entry-reverse" data-reverse-form data-entry-id="<?= e((string) $entry['id']) ?>" hidden>
            <div class="rb-field">
                <label for="reverse-reason-<?= e((string) $entry['id']) ?>">Motif de l'annulation</label>
                <input type="text" id="reverse-reason-<?= e((string) $entry['id']) ?>" name="reason" class="rb-input" maxlength="255" required autocomplete="off">
                <p class="rb-field-error" role="alert" data-field-error="reason" hidden></p>
            </div>
            <div class="rb-field">
                <label for="reverse-date-<?= e((string) $entry['id']) ?>">Date de l'annulation</label>
                <input type="date" id="reverse-date-<?= e((string) $entry['id']) ?>" name="occurredOn" class="rb-input" required>
                <p class="rb-field-error" role="alert" data-field-error="occurredOn" hidden></p>
            </div>
            <p class="rb-field-error" role="alert" data-form-error hidden></p>
            <div class="rb-entry-actions">
                <button type="submit" class="rb-btn rb-btn-danger" data-action="confirm-reverse">Annuler l'écriture</button>
                <button type="button" class="rb-btn" data-action="cancel-reverse">Garder</button>
            </div>
        </form>
    <?php endif; ?>
</li>
