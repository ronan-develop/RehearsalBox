<?php
/**
 * Cartes des créneaux exceptionnels de la semaine (#243) : UN gabarit pour la page du tableau de bord et pour le fragment renvoyé
 * après l'acceptation d'une demande (le navigateur n'a plus de copie du balisage). Cartes non cliquables (#81) : contrairement au
 * planning fixe, un créneau occasionnel n'ouvre pas de conversation — ni role="button", ni tabindex, ni data-contact-group-*.
 *
 * @var list<\App\Planning\Entity\RequestableSlot> $exceptionalPlanningSlots
 */
foreach ($exceptionalPlanningSlots as $requestableSlot):
    $slot = $requestableSlot->slot(); ?>
<article class="rb-planning-card rb-planning-card--exceptional">
    <span class="rb-badge" aria-hidden="true">Occasionnel</span>
    <h4 class="rb-planning-card-group"><?= e($requestableSlot->groupName()) ?></h4>
    <p class="rb-planning-card-when"><span class="rb-planning-card-weekday"><?= e(formatWeekday($slot->weekday())) ?></span> <span class="rb-planning-card-date"><?= e($requestableSlot->occurrenceDate()?->format('d/m/Y') ?? '') ?></span></p>
    <p class="rb-planning-card-time"><?= e(formatTime($slot->startTime())) ?> – <?= e(formatTime($slot->endTime())) ?></p>
</article>
<?php endforeach; ?>
