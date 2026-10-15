<?php
/**
 * Contenu de la piste du planning (#201, #461) : un titre par jour, puis ses cartes triées par heure. Un créneau fixe est une carte
 * cliquable ; l'occasionnel de la semaine (échange accepté, réservation validée) s'y glisse à son jour avec son affichage spécial, et
 * le créneau fixe dont une partie est cédée le dit. UN gabarit pour la page et pour le fragment rechargé après une acceptation.
 *
 * @var list<\App\Planning\Presenter\PlanningDay> $planningDays
 * @var array<int, mixed>                          $currentUserGroupRoles rôle de la personne par groupe (vide : aucun groupe)
 */
foreach ($planningDays as $day): ?>
    <h3 class="rb-planning-day" data-planning-day="<?= e((string) $day->weekday()->value) ?>"<?= $day->isToday() ? ' data-today' : '' ?>>
        <?= e(formatWeekday($day->weekday())) ?>
        <?php if ($day->isToday()): ?><span class="rb-planning-day-today">Aujourd’hui</span><?php endif; ?>
    </h3>
    <?php foreach ($day->slots() as $requestableSlot): ?>
        <?php
        if (!$requestableSlot->isRecurring()) {
            require __DIR__ . '/_occasional-card.php';
            continue;
        }
        $slot = $requestableSlot->slot();
        $groupRole = $currentUserGroupRoles[$requestableSlot->groupId()] ?? null;
        ?>
        <rb-planning-card class="rb-planning-card" role="button" tabindex="0" group-id="<?= e((string) $requestableSlot->groupId()) ?>" group-name="<?= e($requestableSlot->groupName()) ?>" group-slug="<?= e(\App\Support\Slug::from($requestableSlot->groupName())) ?>" weekday="<?= e((string) $slot->weekday()->value) ?>" start-time="<?= e(formatTime($slot->startTime())) ?>" end-time="<?= e(formatTime($slot->endTime())) ?>"<?= $day->isToday() ? ' data-today' : '' ?><?= $currentUserGroupRoles !== [] ? ' can-book' : '' ?><?= $groupRole !== null ? ' member' : '' ?>>
            <?php if ($day->isToday()): ?><span class="rb-badge rb-planning-card-today">Aujourd’hui</span><?php endif; ?>
            <h4 class="rb-planning-card-group"><?= e($requestableSlot->groupName()) ?></h4>
            <p class="rb-planning-card-weekday"><?= e(formatWeekday($slot->weekday())) ?></p>
            <p class="rb-planning-card-time"><?= e(formatTime($slot->startTime())) ?> – <?= e(formatTime($slot->endTime())) ?></p>
            <?php foreach ($day->cessionsFor($requestableSlot) as $cession): ?>
                <p class="rb-planning-card-ceded">Cédé à <?= e($cession->groupName()) ?> le <?= e($cession->date()->format('d/m')) ?><?= $cession->partialRange() !== null ? ' (' . e($cession->partialRange()) . ')' : '' ?></p>
            <?php endforeach; ?>
        </rb-planning-card>
    <?php endforeach; ?>
<?php endforeach; ?>
