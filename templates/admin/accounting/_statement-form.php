<?php
/**
 * Dépôt d'un relevé (panneau replié) : fichier, période, solde final facultatif. « Remplacer » sur un relevé ouvre le même panneau avec
 * l'identifiant du relevé remplacé. Enregistrement par l'API (JS, envoi multipart).
 *
 * @var array<string, mixed> $year
 */
?>
<div class="rb-ledger-new">
    <button type="button" class="rb-btn rb-btn-primary" data-action="new-statement">Déposer un relevé</button>

    <form class="rb-card rb-statement-form" data-statement-form data-year-id="<?= e((string) $year['id']) ?>" hidden>
        <h2 data-statement-title>Déposer un relevé</h2>
        <input type="hidden" name="replaces" value="">
        <div class="rb-field">
            <label for="statement-file">Fichier (PDF, JPEG ou PNG, 10 Mo au plus)</label>
            <input type="file" id="statement-file" name="file" class="rb-input" accept="application/pdf,image/jpeg,image/png" required>
            <p class="rb-field-error" role="alert" data-field-error="file" hidden></p>
        </div>
        <div class="rb-field">
            <label for="statement-start">Début de la période</label>
            <input type="date" id="statement-start" name="periodStart" class="rb-input" required>
            <p class="rb-field-error" role="alert" data-field-error="periodStart" hidden></p>
        </div>
        <div class="rb-field">
            <label for="statement-end">Fin de la période</label>
            <input type="date" id="statement-end" name="periodEnd" class="rb-input" required>
            <p class="rb-field-error" role="alert" data-field-error="periodEnd" hidden></p>
        </div>
        <div class="rb-field">
            <label for="statement-balance">Solde final du relevé (en euros, facultatif)</label>
            <input type="text" id="statement-balance" name="closingBalance" class="rb-input" inputmode="decimal" placeholder="0,00" autocomplete="off">
            <p class="rb-field-hint">Permet de comparer le relevé au livre et de repérer un oubli.</p>
            <p class="rb-field-error" role="alert" data-field-error="closingBalance" hidden></p>
        </div>
        <p class="rb-field-error" role="alert" data-field-error="replaces" hidden></p>
        <p class="rb-field-error" role="alert" data-form-error hidden></p>
        <div class="rb-entry-actions">
            <button type="submit" class="rb-btn rb-btn-primary">Enregistrer le relevé</button>
            <button type="button" class="rb-btn" data-action="cancel-statement">Annuler</button>
        </div>
    </form>
</div>
