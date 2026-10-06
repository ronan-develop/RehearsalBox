<?php
/**
 * Carte d'un échange entre groupes du bloc « Demandes de créneau » (#292, #303) : choisie par RequestKind::Echange.
 *
 * @var \App\Entity\DashboardExceptionItem $item
 * @var int $deckPosition
 */
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
                <span class="rb-badge rb-badge-kind rb-badge-kind--echange"><?= e($item->kind()->label()) ?></span>
                <span class="rb-badge <?= e(formatExceptionStatusBadgeClass($exception->status())) ?>"><?= e(formatExceptionStatus($exception->status())) ?></span>
            </div>
        </div>
        <?php if ($exception->isEnAttente() && $item->holderGroupName() !== null): ?>
            <p class="rb-exception-card-validator">À valider par <?= e($item->holderGroupName()) ?></p>
        <?php endif; ?>
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
