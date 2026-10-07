<?php
/**
 * Réservations libres à valider (#263). Rendu par le serveur ; chaque carte est un composant <rb-booking-card> qui émet la
 * décision, le script de page appelle l'API (jamais un envoi de formulaire avec rechargement).
 *
 * @var string $csrfToken
 * @var list<array{id: int, groupName: string, when: string, range: string, reason: ?string}> $items
 * @var \App\Account\Entity\UserRole $currentUserRole
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <title>Admin — Réservations — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
</head>
<body>
    <div class="rb-admin-page">
        <?php $adminTab = 'bookings'; require __DIR__ . '/../../partials/admin-tabs.php'; ?>
        <h1>Réservations</h1>
        <p class="rb-admin-subtitle">Les groupes réservent une plage libre du local ; le premier administrateur qui répond tranche.</p>

        <div class="rb-booking-list" data-booking-list>
            <?php foreach ($items as $item): ?>
                <rb-booking-card class="rb-booking-card rb-card" data-id="<?= e((string) $item['id']) ?>">
                    <h2 class="rb-booking-card-group"><?= e($item['groupName']) ?></h2>
                    <p class="rb-booking-card-when"><strong><?= e($item['when']) ?></strong> <span><?= e($item['range']) ?></span></p>
                    <?php if ($item['reason'] !== null): ?>
                        <p class="rb-booking-card-reason">Motif : <?= e($item['reason']) ?></p>
                    <?php endif; ?>
                    <div class="rb-booking-card-actions">
                        <button type="button" class="rb-btn rb-btn-primary" data-booking-approve>Valider</button>
                        <button type="button" class="rb-btn rb-btn-danger" data-booking-refuse aria-expanded="false" aria-controls="booking-refusal-<?= e((string) $item['id']) ?>">Refuser</button>
                    </div>
                    <div class="rb-booking-card-refusal" id="booking-refusal-<?= e((string) $item['id']) ?>" data-booking-refusal hidden>
                        <label for="booking-note-<?= e((string) $item['id']) ?>">Motif du refus (facultatif)</label>
                        <input type="text" id="booking-note-<?= e((string) $item['id']) ?>" name="note" class="rb-input" maxlength="255" autocomplete="off">
                        <button type="button" class="rb-btn rb-btn-danger" data-booking-confirm-refuse>Confirmer le refus</button>
                    </div>
                    <p class="rb-field-error" role="alert" data-booking-error hidden></p>
                </rb-booking-card>
            <?php endforeach; ?>
        </div>
        <p class="rb-admin-note" data-booking-empty<?= $items === [] ? '' : ' hidden' ?>>Aucune réservation à valider.</p>
    </div>

    <?php require __DIR__ . '/../../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
