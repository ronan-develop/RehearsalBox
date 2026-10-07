<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <title>Disponibilités — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/dashboard.css">
    <link rel="stylesheet" href="/assets/css/pages/messages.css">
</head>
<body>
    <?php // Barre du haut : invisible au repos, elle apparaît quand le logo y migre en descendant (logo-migration.js). ?>
    <div class="rb-topbar" data-topbar aria-hidden="true"></div>
    <span class="rb-page-bg-text" data-logo data-text="#B27" aria-hidden="true">#B27</span>
    <div class="rb-dashboard-page">
        <header class="rb-dashboard-header rb-stone-panel rb-stone-panel--ember">
            <div class="rb-dashboard-header-user">
                <span><?= e($currentUserGroupName ?? 'Admin local') ?></span>
                <span class="rb-dashboard-avatar" aria-hidden="true"><?= e($currentUserInitials) ?></span>
            </div>
        </header>

        <?php if ($currentUserGroupRoles !== []): ?>
            <p class="rb-dashboard-book"><a href="/bookings" class="rb-btn">Réserver le local</a></p>
        <?php else: ?>
            <p class="rb-dashboard-book-hint">Une réservation du local se fait au nom d'un groupe : ce compte n'appartient à aucun groupe.</p>
        <?php endif; ?>

        <?php if ($planningDays !== []): ?>
            <div class="rb-field">
                <label for="planning-search" class="rb-visually-hidden">Rechercher un groupe ou un jour</label>
                <input type="search" id="planning-search" class="rb-input" placeholder="Rechercher un groupe ou un jour…" data-planning-search>
            </div>
        <?php endif; ?>

        <?php
        $renderPlanningCard = static function ($requestableSlot) use ($currentUserGroupRoles) {
            $slot = $requestableSlot->slot();
            $groupRole = $currentUserGroupRoles[$requestableSlot->groupId()] ?? null;
            ?>
            <article class="rb-planning-card" role="button" tabindex="0" data-contact-group-id="<?= e((string) $requestableSlot->groupId()) ?>" data-contact-group-name="<?= e($requestableSlot->groupName()) ?>" data-contact-group-slug="<?= e(\App\Support\Slug::from($requestableSlot->groupName())) ?>" data-weekday="<?= e((string) $slot->weekday()->value) ?>"<?= $groupRole !== null ? ' data-current-user-group-role="' . e($groupRole->value) . '"' : '' ?>>
                <h4 class="rb-planning-card-group"><?= e($requestableSlot->groupName()) ?></h4>
                <p class="rb-planning-card-weekday"><?= e(formatWeekday($slot->weekday())) ?></p>
                <p class="rb-planning-card-time"><?= e(formatTime($slot->startTime())) ?> – <?= e(formatTime($slot->endTime())) ?></p>
            </article>
            <?php
        };

        ?>

        <?php // Mobile (< 768 px) : deux onglets, une liste à la fois ; bureau : les deux sections côte à côte, en carrousels (#201). Un seul balisage, restylé par le CSS. ?>
        <div class="rb-planning" data-planning-tabs>
            <div class="rb-planning-tabs" role="tablist" aria-label="Type de créneau">
                <button type="button" role="tab" class="rb-planning-tab" id="planning-tab-regular" aria-controls="planning-panel-regular" aria-selected="true" data-planning-tab="regular">Planning</button>
                <button type="button" role="tab" class="rb-planning-tab" id="planning-tab-exceptional" aria-controls="planning-panel-exceptional" aria-selected="false" data-planning-tab="exceptional">Exceptionnels <span class="rb-planning-tab-count" data-planning-tab-count><?= e((string) count($exceptionalPlanningSlots)) ?></span></button>
            </div>

            <section class="rb-planning-section is-active" id="planning-panel-regular" role="tabpanel" aria-labelledby="planning-tab-regular" data-planning-panel="regular">
                <h2>Planning :</h2>
                <?php if ($planningDays === []): ?>
                    <p class="rb-planning-empty">Aucun créneau fixe pour le moment.</p>
                <?php else: ?>
                    <div class="rb-planning-slider" data-planning-slider>
                        <div class="rb-planning-track" data-planning-track>
                            <?php foreach ($planningDays as $day): ?>
                                <h3 class="rb-planning-day" data-planning-day="<?= e((string) $day->weekday()->value) ?>"<?= $day->isToday() ? ' data-today' : '' ?>>
                                    <?= e(formatWeekday($day->weekday())) ?>
                                    <?php if ($day->isToday()): ?><span class="rb-planning-day-today">Aujourd’hui</span><?php endif; ?>
                                </h3>
                                <?php foreach ($day->slots() as $requestableSlot): ?>
                                    <?php $renderPlanningCard($requestableSlot); ?>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <?php // Section toujours rendue : #79 doit pouvoir la remplir dynamiquement après une acceptation, sans reload complet. Vide : masquée sur bureau, message sur mobile. ?>
            <section class="rb-planning-section<?= $exceptionalPlanningSlots === [] ? ' rb-planning-section--empty' : '' ?>" id="planning-panel-exceptional" role="tabpanel" aria-labelledby="planning-tab-exceptional" data-planning-panel="exceptional" data-exceptional-planning-section>
                <h2>Créneaux exceptionnels :</h2>
                <p class="rb-planning-empty" data-planning-empty>Aucun créneau exceptionnel cette semaine.</p>
                <div class="rb-planning-slider rb-planning-slider--exceptional" data-planning-slider-exceptional>
                    <div class="rb-planning-track" data-planning-track-exceptional>
                        <?php require __DIR__ . '/_exceptional-cards.php'; ?>
                    </div>
                </div>
            </section>
        </div>

        <section class="rb-exceptions-section">
            <h2>Demandes de créneau</h2>
            <div class="rb-exceptions-tabs" role="tablist">
                <button type="button" class="rb-exceptions-tab" role="tab" aria-selected="true" data-tab-target="received">
                    Reçues<?php if ($receivedExceptions !== []): ?> <span class="rb-badge rb-badge-warn"><?= e((string) count($receivedExceptions)) ?></span><?php endif; ?>
                </button>
                <button type="button" class="rb-exceptions-tab" role="tab" aria-selected="false" data-tab-target="sent">Envoyées</button>
                <button type="button" class="rb-exceptions-tab" role="tab" aria-selected="false" data-tab-target="archived">Archivées</button>
            </div>

            <div class="rb-exception-deck" data-exception-deck data-deck="received">
                <?php foreach ($receivedExceptions as $deckPosition => $item): ?>
                    <?php require __DIR__ . '/_request-card-' . $item->kind()->value . '.php'; ?>
                <?php endforeach; ?>
                <div class="rb-exception-empty"<?= $receivedExceptions !== [] ? ' hidden' : '' ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>
                    <p>Aucune demande reçue en attente.</p>
                </div>
            </div>

            <div class="rb-exception-deck" data-exception-deck data-deck="sent" hidden>
                <?php foreach ($sentExceptions as $deckPosition => $item): ?>
                    <?php require __DIR__ . '/_request-card-' . $item->kind()->value . '.php'; ?>
                <?php endforeach; ?>
                <div class="rb-exception-empty"<?= $sentExceptions !== [] ? ' hidden' : '' ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>
                    <p>Aucune demande envoyée en attente.</p>
                </div>
            </div>

            <div class="rb-exception-deck" data-exception-deck data-deck="archived" hidden>
                <?php foreach ($archivedExceptions as $deckPosition => $item): ?>
                    <?php require __DIR__ . '/_request-card-' . $item->kind()->value . '.php'; ?>
                <?php endforeach; ?>
                <div class="rb-exception-empty"<?= $archivedExceptions !== [] ? ' hidden' : '' ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>
                    <p>Aucune demande archivée.</p>
                </div>
            </div>
        </section>

        <a href="/messages" class="rb-messages-link" data-messages-link>
            <span>Messages</span>
            <span class="rb-badge rb-badge-warn" data-messages-link-badge hidden></span>
        </a>
    </div>
    <?php require __DIR__ . '/../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
