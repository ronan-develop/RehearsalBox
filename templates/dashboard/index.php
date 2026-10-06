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
    <div class="rb-page-bg" aria-hidden="true">
        <span class="rb-page-bg-text" data-parallax="bg" data-text="#B27">#B27</span>
    </div>
    <div class="rb-dashboard-page">
        <header class="rb-dashboard-header rb-stone-panel rb-stone-panel--ember">
            <?php if ($currentUserGroupRole === \App\Entity\Enum\GroupUserRole::Gestionnaire): ?>
                <button type="button" class="rb-group-photo rb-group-photo--editable"
                        title="Modifier la photo du groupe" aria-label="Modifier la photo du groupe">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="12" cy="12" r="3.5"/>
                        <path d="M8 5l1.5-2h5L16 5"/>
                    </svg>
                    <span class="rb-group-photo-edit-badge" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true">
                            <path d="M12 5v14M5 12h14"/>
                        </svg>
                    </span>
                </button>
            <?php elseif ($currentUserGroupId !== null): ?>
                <span class="rb-group-photo" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="12" cy="12" r="3.5"/>
                        <path d="M8 5l1.5-2h5L16 5"/>
                    </svg>
                </span>
            <?php endif; ?>
            <div class="rb-dashboard-header-user">
                <span><?= e($currentUserGroupName ?? 'Admin local') ?></span>
                <span class="rb-dashboard-avatar" aria-hidden="true"><?= e($currentUserInitials) ?></span>
            </div>
        </header>

        <?php if ($planningSlots !== []): ?>
            <div class="rb-field">
                <label for="planning-search" class="rb-visually-hidden">Rechercher un groupe ou un jour</label>
                <input type="search" id="planning-search" class="rb-input" placeholder="Rechercher un groupe ou un jour…" data-planning-search>
            </div>

            <?php
            $renderPlanningCard = static function ($requestableSlot) use ($currentUserGroupRoles) {
                $slot = $requestableSlot->slot();
                $groupRole = $currentUserGroupRoles[$requestableSlot->groupId()] ?? null;
                ?>
                <article class="rb-planning-card" role="button" tabindex="0" data-contact-group-id="<?= e((string) $requestableSlot->groupId()) ?>" data-contact-group-name="<?= e($requestableSlot->groupName()) ?>" data-contact-group-slug="<?= e(\App\Support\Slug::from($requestableSlot->groupName())) ?>" data-weekday="<?= e((string) $slot->weekday()->value) ?>"<?= $groupRole !== null ? ' data-current-user-group-role="' . e($groupRole->value) . '"' : '' ?>>
                    <h3 class="rb-planning-card-group"><?= e($requestableSlot->groupName()) ?></h3>
                    <p class="rb-planning-card-weekday"><?= e(formatWeekday($slot->weekday())) ?></p>
                    <p class="rb-planning-card-time"><?= e(formatTime($slot->startTime())) ?> – <?= e(formatTime($slot->endTime())) ?></p>
                </article>
                <?php
            };
            ?>
            <section class="rb-planning-section">
                <h2>Planning :</h2>
                <div class="rb-planning-slider" data-planning-slider>
                    <div class="rb-planning-track" data-planning-track>
                        <?php foreach ($planningSlots as $requestableSlot): ?>
                            <?php $renderPlanningCard($requestableSlot); ?>
                        <?php endforeach; ?>
                        <?php // Copie dupliquée pour boucler le défilement sans saut visuel (cf. planning-slider.js). ?>
                        <div aria-hidden="true" style="display: contents;">
                            <?php foreach ($planningSlots as $requestableSlot): ?>
                                <?php $renderPlanningCard($requestableSlot); ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php
        /**
         * Cartes non cliquables (#81) : contrairement au planning fixe,
         * un créneau occasionnel n'ouvre pas la modale de contact — pas
         * de role="button"/tabindex, pas de data-contact-group-*.
         */
        $renderExceptionalCard = static function ($requestableSlot) {
            $slot = $requestableSlot->slot();
            ?>
            <article class="rb-planning-card rb-planning-card--exceptional">
                <span class="rb-badge" aria-hidden="true">Occasionnel</span>
                <h3 class="rb-planning-card-group"><?= e($requestableSlot->groupName()) ?></h3>
                <p class="rb-planning-card-weekday"><?= e(formatWeekday($slot->weekday())) ?></p>
                <p class="rb-planning-card-date"><?= e($requestableSlot->occurrenceDate()?->format('d/m/Y') ?? '') ?></p>
                <p class="rb-planning-card-time"><?= e(formatTime($slot->startTime())) ?> – <?= e(formatTime($slot->endTime())) ?></p>
            </article>
            <?php
        };
        ?>
        <?php // Section toujours rendue (hidden si vide) : #79 doit pouvoir la révéler dynamiquement après une acceptation, sans reload complet. ?>
        <section class="rb-planning-section"<?= $exceptionalPlanningSlots === [] ? ' hidden' : '' ?> data-exceptional-planning-section>
            <h2>Créneaux exceptionnels :</h2>
            <div class="rb-planning-slider rb-planning-slider--exceptional" data-planning-slider-exceptional>
                <div class="rb-planning-track" data-planning-track-exceptional>
                    <?php foreach ($exceptionalPlanningSlots as $requestableSlot): ?>
                        <?php $renderExceptionalCard($requestableSlot); ?>
                    <?php endforeach; ?>
                    <?php // Copie dupliquée pour boucler le défilement sans saut visuel, seulement utile si le contrôleur d'auto-scroll s'active (cf. planning-slider.js). ?>
                    <div aria-hidden="true" style="display: contents;">
                        <?php foreach ($exceptionalPlanningSlots as $requestableSlot): ?>
                            <?php $renderExceptionalCard($requestableSlot); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>

        <span class="rb-parallax-anchor" data-parallax-anchor aria-hidden="true"></span>

        <?php
        $renderExceptionCard = static function (\App\Entity\DashboardExceptionItem $item, int $deckPosition) {
            $exception = $item->exception();
            $isRecue = $item->direction() === \App\Entity\Enum\ExceptionDirection::Recue;
            $slot = $item->slot();
            $initials = \App\Support\Initials::from($item->requestedByGroupName());
            // Profondeur de pile visible plafonnée (cf. exception-deck.js
            // MAX_VISIBLE_DEPTH) : au-delà, les cartes se superposent sans
            // creuser davantage l'offset visuel, pour ne jamais déborder
            // avec un historique archivé volumineux.
            $visualDeckIndex = min($deckPosition, 2);
            ?>
            <article class="rb-exception-card rb-stone-surface<?= $deckPosition === 0 ? ' rb-exception-card--active' : '' ?>" data-exception-id="<?= e((string) $exception->id()) ?>"
                     style="--deck-index: <?= e((string) $visualDeckIndex) ?>; --group-color: <?= e(\App\Support\SafeColor::from($item->requestedByGroupColorHex()) ?? 'var(--rb-accent)') ?>;">
                <div class="rb-exception-card-head">
                    <span class="rb-exception-card-avatar" aria-hidden="true"><?= e($initials) ?></span>
                    <div class="rb-exception-card-head-text">
                        <h3><?= e($item->requestedByGroupName()) ?></h3>
                        <span class="rb-badge <?= e(formatExceptionStatusBadgeClass($exception->status())) ?>"><?= e(formatExceptionStatus($exception->status())) ?></span>
                    </div>
                </div>
                <?php if ($slot !== null): ?>
                    <div class="rb-exception-card-slot">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>
                        <strong><?= e(formatWeekday($slot->weekday())) ?> <?= e($exception->occurrenceDate()->format('d/m/Y')) ?> <?= e(formatTime($slot->startTime())) ?> – <?= e(formatTime($slot->endTime())) ?></strong>
                    </div>
                <?php endif; ?>
                <?php if ($exception->requestReason() !== null): ?>
                    <p class="rb-exception-card-body"><?= e($exception->requestReason()) ?></p>
                <?php endif; ?>
                <?php if ($isRecue && $exception->isEnAttente()): ?>
                    <div class="rb-exception-card-actions">
                        <button type="button" class="rb-btn rb-btn-danger" data-respond-button data-accepted="false"
                                data-exception-id="<?= e((string) $exception->id()) ?>" data-occurrence-date="<?= e($exception->occurrenceDate()->format('Y-m-d')) ?>">
                            Refuser
                        </button>
                        <button type="button" class="rb-btn rb-btn-primary" data-respond-button data-accepted="true"
                                data-exception-id="<?= e((string) $exception->id()) ?>" data-occurrence-date="<?= e($exception->occurrenceDate()->format('Y-m-d')) ?>">
                            Accepter
                        </button>
                    </div>
                <?php elseif (!$isRecue && $exception->isEnAttente()): ?>
                    <form data-update-form data-exception-id="<?= e((string) $exception->id()) ?>">
                        <div class="rb-field">
                            <label for="occurrence-date-<?= e((string) $exception->id()) ?>">Date précise</label>
                            <input type="date" id="occurrence-date-<?= e((string) $exception->id()) ?>" name="occurrenceDate"
                                   class="rb-input" value="<?= e($exception->occurrenceDate()->format('Y-m-d')) ?>" required>
                        </div>
                        <div class="rb-field">
                            <label for="request-reason-<?= e((string) $exception->id()) ?>">Raison (optionnel)</label>
                            <input type="text" id="request-reason-<?= e((string) $exception->id()) ?>" name="reason"
                                   class="rb-input" value="<?= e($exception->requestReason() ?? '') ?>">
                        </div>
                        <div class="rb-exception-card-actions">
                            <button type="button" class="rb-btn rb-btn-danger" data-cancel-button
                                    data-exception-id="<?= e((string) $exception->id()) ?>">
                                Annuler
                            </button>
                            <button type="submit" class="rb-btn rb-btn-primary">Modifier</button>
                        </div>
                    </form>
                <?php endif; ?>
            </article>
            <?php
        };
        ?>
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
                    <?php $renderExceptionCard($item, $deckPosition); ?>
                <?php endforeach; ?>
                <div class="rb-exception-empty"<?= $receivedExceptions !== [] ? ' hidden' : '' ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>
                    <p>Aucune demande reçue en attente.</p>
                </div>
            </div>

            <div class="rb-exception-deck" data-exception-deck data-deck="sent" hidden>
                <?php foreach ($sentExceptions as $deckPosition => $item): ?>
                    <?php $renderExceptionCard($item, $deckPosition); ?>
                <?php endforeach; ?>
                <div class="rb-exception-empty"<?= $sentExceptions !== [] ? ' hidden' : '' ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>
                    <p>Aucune demande envoyée en attente.</p>
                </div>
            </div>

            <div class="rb-exception-deck" data-exception-deck data-deck="archived" hidden>
                <?php foreach ($archivedExceptions as $deckPosition => $item): ?>
                    <?php $renderExceptionCard($item, $deckPosition); ?>
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
