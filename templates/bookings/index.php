<?php
/**
 * Réserver le local (#263). Rendu par le serveur ; <rb-booking-form> (saisie, plan, un seul bouton) et <rb-booking-item> (annulation)
 * n'appellent jamais l'API : ils émettent des évènements, bookings.js appelle l'API. Sans JavaScript la page reste lisible.
 *
 * @var string $csrfToken
 * @var list<array{id: int, name: string, bookings: list<array<string, mixed>>}> $groups
 * @var string $minDate
 * @var string $maxDate
 * @var \App\Entity\Enum\UserRole $currentUserRole
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <title>Réserver le local — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/bookings.css">
</head>
<body>
    <div class="rb-bookings-page">
        <a href="/" class="rb-bookings-back">← Retour aux disponibilités</a>
        <h1>Réserver le local</h1>
        <p class="rb-bookings-intro">Choisissez un jour et une plage. Le local est réservé aux créneaux fixes des groupes : si votre plage les chevauche, le site réserve la partie libre et vous propose de demander le reste au groupe concerné.</p>

        <?php if ($groups === []): ?>
            <p class="rb-bookings-empty">Vous devez appartenir à un groupe pour réserver le local.</p>
        <?php else: ?>
            <rb-booking-form class="rb-booking-form rb-card">
                <?php if (count($groups) === 1): ?>
                    <input type="hidden" name="groupId" value="<?= e((string) $groups[0]['id']) ?>">
                    <p class="rb-booking-form-group">Groupe : <strong><?= e($groups[0]['name']) ?></strong></p>
                <?php else: ?>
                    <div class="rb-field">
                        <label for="booking-group">Groupe</label>
                        <select id="booking-group" name="groupId" class="rb-input" required>
                            <?php foreach ($groups as $group): ?>
                                <option value="<?= e((string) $group['id']) ?>"><?= e($group['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="rb-field">
                    <label for="booking-date">Jour</label>
                    <input type="date" id="booking-date" name="date" class="rb-input" min="<?= e($minDate) ?>" max="<?= e($maxDate) ?>" required>
                </div>
                <div class="rb-booking-form-times">
                    <div class="rb-field">
                        <label for="booking-start">De</label>
                        <input type="time" id="booking-start" name="start" class="rb-input" step="900" required>
                    </div>
                    <div class="rb-field">
                        <label for="booking-end">À</label>
                        <input type="time" id="booking-end" name="end" class="rb-input" step="900" required>
                    </div>
                </div>
                <div class="rb-field">
                    <label for="booking-reason">Motif (facultatif)</label>
                    <input type="text" id="booking-reason" name="reason" class="rb-input" maxlength="255" autocomplete="off">
                </div>

                <ul class="rb-booking-plan" data-booking-plan aria-live="polite" hidden></ul>
                <p class="rb-field-error" role="alert" data-booking-form-error hidden></p>
                <button type="button" class="rb-btn rb-btn-primary rb-booking-submit" data-booking-submit disabled>Réserver</button>
                <ul class="rb-booking-result" data-booking-result aria-live="polite" hidden></ul>
            </rb-booking-form>

            <h2>Réservations de votre groupe</h2>
            <?php foreach ($groups as $group): ?>
                <section class="rb-booking-group" aria-label="Réservations de <?= e($group['name']) ?>">
                    <?php if (count($groups) > 1): ?><h3><?= e($group['name']) ?></h3><?php endif; ?>
                    <?php if ($group['bookings'] === []): ?>
                        <p class="rb-bookings-empty">Aucune réservation à venir.</p>
                    <?php else: ?>
                        <ul class="rb-booking-items" data-booking-items>
                            <?php foreach ($group['bookings'] as $item): ?>
                                <li>
                                    <rb-booking-item class="rb-booking-item rb-card" data-id="<?= e((string) $item['id']) ?>">
                                        <p class="rb-booking-item-when"><strong><?= e($item['when']) ?></strong> <span><?= e($item['range']) ?></span></p>
                                        <p class="rb-booking-item-status rb-booking-item-status--<?= e($item['status']) ?>"><?= e($item['statusLabel']) ?></p>
                                        <?php if ($item['reason'] !== null): ?><p class="rb-booking-item-note">Motif : <?= e($item['reason']) ?></p><?php endif; ?>
                                        <?php if ($item['decisionNote'] !== null): ?><p class="rb-booking-item-note">Réponse de l’administrateur : <?= e($item['decisionNote']) ?></p><?php endif; ?>
                                        <?php if ($item['cancellable']): ?>
                                            <button type="button" class="rb-btn" data-booking-cancel>Annuler</button>
                                        <?php endif; ?>
                                        <p class="rb-field-error" role="alert" data-booking-error hidden></p>
                                    </rb-booking-item>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . '/../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
