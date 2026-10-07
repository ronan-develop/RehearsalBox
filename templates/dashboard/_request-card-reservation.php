<?php
/**
 * Carte d'une réservation libre du bloc « Demandes de créneau » (#292, #303) : choisie par RequestKind::Reservation.
 *
 * @var \App\Entity\DashboardBookingItem $item
 * @var int $deckPosition
 */
    $booking = $item->booking();
    $visualDeckIndex = min($deckPosition, 2);
    $statusLabel = match ($booking->status()) {
        \App\Entity\Enum\FreeSlotBookingStatus::EnAttente => 'En attente',
        \App\Entity\Enum\FreeSlotBookingStatus::Validee => 'Validée',
        \App\Entity\Enum\FreeSlotBookingStatus::Refusee => 'Refusée',
        \App\Entity\Enum\FreeSlotBookingStatus::Annulee => 'Annulée',
    };
    $statusClass = match ($booking->status()) {
        \App\Entity\Enum\FreeSlotBookingStatus::EnAttente => 'rb-badge-warn',
        \App\Entity\Enum\FreeSlotBookingStatus::Validee => 'rb-badge-ok',
        default => 'rb-badge-err',
    };
    ?>
    <article class="rb-exception-card rb-stone-surface<?= $deckPosition === 0 ? ' rb-exception-card--active' : '' ?>" data-booking-id="<?= e((string) $booking->id()) ?>"
             style="--deck-index: <?= e((string) $visualDeckIndex) ?>; --group-color: <?= e(\App\Support\SafeColor::from($item->groupColorHex()) ?? 'var(--rb-accent)') ?>;">
        <div class="rb-exception-card-head">
            <?php $avatarInitials = \App\Support\Initials::from($item->groupName()); $avatarClass = ''; $avatarColor = null; $avatarTitle = null; require __DIR__ . '/../partials/avatar.php'; ?>
            <div class="rb-exception-card-head-text">
                <h3><?= e($item->groupName()) ?></h3>
                <span class="rb-badge rb-badge-kind rb-badge-kind--reservation"><?= e($item->kind()->label()) ?></span>
                <span class="rb-badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span>
            </div>
        </div>
        <?php if ($booking->status() === \App\Entity\Enum\FreeSlotBookingStatus::EnAttente): ?>
            <p class="rb-exception-card-validator">À valider par les admins</p>
        <?php endif; ?>
        <div class="rb-exception-card-slot">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>
            <strong><?= e(formatWeekday(\App\Entity\Enum\Weekday::from((int) $booking->date()->format('N') - 1))) ?> <?= e($booking->date()->format('d/m/Y')) ?> <?= e(substr($booking->range()->start(), 0, 5)) ?> – <?= e(substr($booking->range()->end(), 0, 5)) ?></strong>
        </div>
        <?php if ($booking->reason() !== null): ?>
            <p class="rb-exception-card-body"><?= e($booking->reason()) ?></p>
        <?php endif; ?>
        <?php if ($booking->decisionNote() !== null): ?>
            <p class="rb-exception-card-body">Réponse : <?= e($booking->decisionNote()) ?></p>
        <?php endif; ?>
        <?php if ($booking->status() === \App\Entity\Enum\FreeSlotBookingStatus::EnAttente): ?>
            <rb-booking-item class="rb-exception-card-actions" data-id="<?= e((string) $booking->id()) ?>">
                <button type="button" class="rb-btn rb-btn-danger" data-booking-cancel>Annuler</button>
                <p class="rb-field-error" role="alert" data-booking-error hidden></p>
            </rb-booking-item>
        <?php endif; ?>
    </article>
