<?php
/**
 * Carte d'un créneau occasionnel (#243, #461) : échange accepté ou réservation validée de la semaine. La DATE est dans la pastille (à
 * la place du mot « Occasionnel », dit aux lecteurs d'écran), pour lever toute ambiguïté avec un créneau fixe récurrent. Non cliquable
 * (#81) : ni role="button", ni tabindex. `group-name` et `weekday` servent à la recherche du planning.
 *
 * @var \App\Planning\Entity\RequestableSlot $requestableSlot
 */
$slot = $requestableSlot->slot();
?>
<article class="rb-planning-card rb-planning-card--exceptional" group-name="<?= e($requestableSlot->groupName()) ?>" weekday="<?= e((string) $slot->weekday()->value) ?>">
    <span class="rb-badge"><span class="rb-visually-hidden">Créneau occasionnel du </span><?= e($requestableSlot->occurrenceDate()?->format('d/m') ?? '') ?></span>
    <h4 class="rb-planning-card-group"><?= e($requestableSlot->groupName()) ?></h4>
    <p class="rb-planning-card-weekday"><?= e(formatWeekday($slot->weekday())) ?></p>
    <p class="rb-planning-card-time"><?= e(formatTime($slot->startTime())) ?> – <?= e(formatTime($slot->endTime())) ?></p>
</article>
