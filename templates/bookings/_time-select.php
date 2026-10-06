<?php
/**
 * Horaire au quart d'heure en deux listes, heures puis minutes (#296) : <rb-time-picker> tient à jour le champ caché « HH:MM »
 * que lit le formulaire. Deux vraies listes natives (accessibles, roue de l'iPhone), sans minute impossible à saisir.
 *
 * @var string $id    id de la liste des heures (cible du libellé du champ)
 * @var string $name  nom du champ caché
 * @var string $label début ou fin, pour les noms accessibles des listes
 * @var string $min   horaire minimal « HH:MM »
 * @var string $max   horaire maximal « HH:MM »
 */
?>
<rb-time-picker class="rb-time-picker" data-min="<?= e($min) ?>" data-max="<?= e($max) ?>">
    <input type="hidden" name="<?= e($name) ?>" value="">
    <select id="<?= e($id) ?>" class="rb-input rb-select" data-time-hours aria-label="<?= e($label) ?> : heures" required>
        <option value="">--</option>
        <?php for ($hour = 0; $hour < 24; ++$hour): ?>
            <option value="<?= e(sprintf('%02d', $hour)) ?>"><?= e(sprintf('%02d', $hour)) ?></option>
        <?php endfor; ?>
    </select>
    <span class="rb-time-picker-colon" aria-hidden="true">:</span>
    <select class="rb-input rb-select" data-time-minutes aria-label="<?= e($label) ?> : minutes" required>
        <option value="">--</option>
        <?php foreach (\App\Support\QuarterHour::range('00:00', '00:45') as $minute): ?>
            <option value="<?= e(substr($minute, 3)) ?>"><?= e(substr($minute, 3)) ?></option>
        <?php endforeach; ?>
    </select>
</rb-time-picker>
