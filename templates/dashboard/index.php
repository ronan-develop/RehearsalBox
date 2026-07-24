<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <title>Disponibilités — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/dashboard.css">
</head>
<body>
    <div class="rb-dashboard-page">
        <header class="rb-dashboard-header">
            <h1>Demandes d'échange de créneaux</h1>
        </header>

        <section class="rb-slot-list" data-pending-list>
            <h2>Demandes reçues à traiter</h2>
            <?php if ($pendingExceptions === []): ?>
                <p class="rb-empty-state">Aucune demande à traiter pour le moment.</p>
            <?php endif; ?>
            <?php foreach ($pendingExceptions as $exception): ?>
                <article class="rb-slot-card rb-card" data-exception-id="<?= e((string) $exception->id()) ?>">
                    <p class="rb-slot-card-date"><?= e($exception->occurrenceDate()->format('d/m/Y')) ?></p>
                    <?php if ($exception->requestReason() !== null): ?>
                        <p class="rb-slot-card-reason"><?= e($exception->requestReason()) ?></p>
                    <?php endif; ?>
                    <button type="button" class="rb-btn rb-btn-primary" data-respond-button data-accepted="true"
                            data-exception-id="<?= e((string) $exception->id()) ?>">
                        Accepter
                    </button>
                    <button type="button" class="rb-btn rb-btn-danger" data-respond-button data-accepted="false"
                            data-exception-id="<?= e((string) $exception->id()) ?>">
                        Refuser
                    </button>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="rb-slot-list" data-requested-list>
            <h2>Mes demandes envoyées</h2>
            <?php if ($requestedExceptions === []): ?>
                <p class="rb-empty-state">Aucune demande envoyée pour le moment.</p>
            <?php endif; ?>
            <?php foreach ($requestedExceptions as $exception): ?>
                <article class="rb-slot-card rb-card">
                    <p class="rb-slot-card-date"><?= e($exception->occurrenceDate()->format('d/m/Y')) ?></p>
                    <p class="rb-slot-card-status">Statut : <?= e($exception->status()->value) ?></p>
                </article>
            <?php endforeach; ?>
        </section>

        <?php if ($groups !== []): ?>
            <section class="rb-card" data-request-form-section>
                <h2>Initier une demande</h2>
                <form data-request-form>
                    <div class="rb-field">
                        <label for="requesting-group">Au nom de</label>
                        <select id="requesting-group" name="requestingGroupId" class="rb-input">
                            <?php foreach ($groups as $group): ?>
                                <option value="<?= e((string) $group->id()) ?>"><?= e($group->name()) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rb-field">
                        <label for="requested-slot">Créneau visé</label>
                        <select id="requested-slot" name="recurringSlotId" class="rb-input">
                            <?php foreach ($requestableSlots as $slot): ?>
                                <option value="<?= e((string) $slot->id()) ?>">
                                    <?= e($slot->weekday()->name) ?> <?= e($slot->startTime()) ?>–<?= e($slot->endTime()) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rb-field">
                        <label for="occurrence-date">Date précise</label>
                        <input type="date" id="occurrence-date" name="occurrenceDate" class="rb-input" required>
                    </div>
                    <div class="rb-field">
                        <label for="request-reason">Raison (optionnel)</label>
                        <input type="text" id="request-reason" name="reason" class="rb-input">
                    </div>
                    <button type="submit" class="rb-btn rb-btn-primary">Envoyer la demande</button>
                </form>
            </section>
        <?php endif; ?>
    </div>
    <?php require __DIR__ . '/../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
