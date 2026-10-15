<?php
/**
 * Une présence du compte rendu (#361) : personne inscrite ou nom libre, présence, pouvoir donné. Ligne en carte ; les champs sont
 * enveloppés dans leur <label> (pas d'id : la ligne est clonée depuis le <template>).
 *
 * @var array{userId: string, name: string, attendance: string, proxyHolder: string} $row
 * @var array{users: list<array{id: string, name: string}>} $form
 */
?>
<div class="rb-assembly-row" data-row>
    <div class="rb-field">
        <label>
            <span>Personne inscrite</span>
            <select name="userId" class="rb-input">
                <option value="">Personne non inscrite</option>
                <?php foreach ($form['users'] as $user): ?>
                    <option value="<?= e($user['id']) ?>"<?= $user['id'] === $row['userId'] ? ' selected' : '' ?>><?= e($user['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <p class="rb-field-error" role="alert" data-field-error="userId" hidden></p>
    </div>
    <div class="rb-field">
        <label>
            <span>Nom (non inscrit)</span>
            <input type="text" name="name" class="rb-input" maxlength="120" placeholder="Nom (non inscrit)" value="<?= e($row['name']) ?>">
        </label>
        <p class="rb-field-error" role="alert" data-field-error="name" hidden></p>
    </div>
    <div class="rb-field">
        <label>
            <span>Présence</span>
            <select name="attendance" class="rb-input">
                <option value="present"<?= $row['attendance'] === 'present' ? ' selected' : '' ?>>Présent·e</option>
                <option value="excuse"<?= $row['attendance'] === 'excuse' ? ' selected' : '' ?>>Excusé·e</option>
                <option value="represente"<?= $row['attendance'] === 'represente' ? ' selected' : '' ?>>Représenté·e</option>
            </select>
        </label>
        <p class="rb-field-error" role="alert" data-field-error="attendance" hidden></p>
    </div>
    <div class="rb-field">
        <label>
            <span>Pouvoir donné à</span>
            <input type="text" name="proxyHolder" class="rb-input" maxlength="120" placeholder="Pouvoir donné à" value="<?= e($row['proxyHolder']) ?>">
        </label>
        <p class="rb-field-error" role="alert" data-field-error="proxyHolder" hidden></p>
    </div>
    <button type="button" class="rb-btn" data-remove-row>Retirer</button>
</div>
