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
            <h1>Créneaux libérés</h1>
            <?php if ($groups !== []): ?>
                <label for="current-group" class="rb-visually-hidden">Revendiquer au nom de</label>
                <select id="current-group" data-current-group-select data-current-group-id="<?= e((string) $groups[0]->id()) ?>">
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= e((string) $group->id()) ?>"><?= e($group->name()) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </header>

        <main class="rb-slot-list" data-slot-list>
            <?php if ($exceptions === []): ?>
                <p class="rb-empty-state">Aucun créneau libéré pour le moment.</p>
            <?php endif; ?>
            <?php foreach ($exceptions as $exception): ?>
                <article class="rb-slot-card" data-exception-id="<?= e((string) $exception->id()) ?>">
                    <p class="rb-slot-card-date"><?= e($exception->occurrenceDate()->format('d/m/Y')) ?></p>
                    <?php if ($exception->releasedReason() !== null): ?>
                        <p class="rb-slot-card-reason"><?= e($exception->releasedReason()) ?></p>
                    <?php endif; ?>
                    <?php if ($groups !== []): ?>
                        <button type="button" class="rb-btn rb-slot-card-claim" data-claim-button
                                data-exception-id="<?= e((string) $exception->id()) ?>">
                            Je prends ce créneau
                        </button>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </main>
    </div>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
