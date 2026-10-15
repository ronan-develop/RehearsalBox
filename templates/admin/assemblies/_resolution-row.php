<?php
/**
 * Une résolution du compte rendu (#361) : texte, décompte des voix et issue. Ligne en carte ; champs dans leur <label> (pas d'id :
 * clonée depuis le <template>).
 *
 * @var array{text: string, for: string, against: string, abstain: string, outcome: string} $row
 */
?>
<div class="rb-assembly-row" data-row>
    <div class="rb-field">
        <label>
            <span>Résolution</span>
            <textarea name="text" class="rb-input" rows="3"><?= e($row['text']) ?></textarea>
        </label>
        <p class="rb-field-error" role="alert" data-field-error="text" hidden></p>
    </div>
    <div class="rb-assembly-votes">
        <div class="rb-field">
            <label>
                <span>Pour</span>
                <input type="number" name="for" class="rb-input" min="0" max="9999" value="<?= e($row['for']) ?>">
            </label>
            <p class="rb-field-error" role="alert" data-field-error="for" hidden></p>
        </div>
        <div class="rb-field">
            <label>
                <span>Contre</span>
                <input type="number" name="against" class="rb-input" min="0" max="9999" value="<?= e($row['against']) ?>">
            </label>
            <p class="rb-field-error" role="alert" data-field-error="against" hidden></p>
        </div>
        <div class="rb-field">
            <label>
                <span>Abstentions</span>
                <input type="number" name="abstain" class="rb-input" min="0" max="9999" value="<?= e($row['abstain']) ?>">
            </label>
            <p class="rb-field-error" role="alert" data-field-error="abstain" hidden></p>
        </div>
    </div>
    <div class="rb-field">
        <label>
            <span>Issue</span>
            <select name="outcome" class="rb-input">
                <option value="">—</option>
                <option value="adoptee"<?= $row['outcome'] === 'adoptee' ? ' selected' : '' ?>>Adoptée</option>
                <option value="rejetee"<?= $row['outcome'] === 'rejetee' ? ' selected' : '' ?>>Rejetée</option>
            </select>
        </label>
        <p class="rb-field-error" role="alert" data-field-error="outcome" hidden></p>
    </div>
    <button type="button" class="rb-btn" data-remove-row>Retirer</button>
</div>
