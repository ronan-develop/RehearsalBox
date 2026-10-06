<?php
/**
 * Liste d'horaires au quart d'heure (#290) : une vraie liste native (accessible, roue de l'iPhone) mais qui ne propose que des heures
 * valables, au lieu du champ « heure » qui laisse saisir 21:01 et affiche un popup blanc.
 *
 * @var string $id
 * @var string $name
 * @var list<string> $times
 */
?>
<select id="<?= e($id) ?>" name="<?= e($name) ?>" class="rb-input rb-select" required>
    <option value="">--:--</option>
    <?php foreach ($times as $time): ?>
        <option value="<?= e($time) ?>"><?= e($time) ?></option>
    <?php endforeach; ?>
</select>
