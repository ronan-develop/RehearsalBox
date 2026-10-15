<?php
/**
 * Un point de l'ordre du jour du compte rendu (#361). Ligne en carte ; champs dans leur <label> (pas d'id : clonée depuis le <template>).
 *
 * @var array{title: string, discussion: string} $row
 */
?>
<div class="rb-assembly-row" data-row>
    <div class="rb-field">
        <label>
            <span>Point</span>
            <input type="text" name="title" class="rb-input" maxlength="200" value="<?= e($row['title']) ?>">
        </label>
        <p class="rb-field-error" role="alert" data-field-error="title" hidden></p>
    </div>
    <div class="rb-field">
        <label>
            <span>Discussion</span>
            <textarea name="discussion" class="rb-input" maxlength="20000" rows="3"><?= e($row['discussion']) ?></textarea>
        </label>
        <p class="rb-field-error" role="alert" data-field-error="discussion" hidden></p>
    </div>
    <button type="button" class="rb-btn" data-remove-row>Retirer</button>
</div>
