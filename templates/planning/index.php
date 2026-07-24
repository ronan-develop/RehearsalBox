<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <title>Planning — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/planning.css">
</head>
<body>
    <div class="rb-planning-page">
        <header class="rb-planning-header">
            <h1>Planning des créneaux fixes</h1>
        </header>

        <?php if ($planningSlots === []): ?>
            <p class="rb-empty-state">Aucun créneau fixe pour le moment.</p>
        <?php else: ?>
            <div class="rb-planning-slider" data-planning-slider>
                <div class="rb-planning-track" data-planning-track>
                    <?php foreach ($planningSlots as $requestableSlot): ?>
                        <?php $slot = $requestableSlot->slot(); ?>
                        <article class="rb-planning-card rb-card">
                            <h3 class="rb-planning-card-group"><?= e($requestableSlot->groupName()) ?></h3>
                            <p class="rb-planning-card-weekday"><?= e($slot->weekday()->name) ?></p>
                            <p class="rb-planning-card-time"><?= e($slot->startTime()) ?> – <?= e($slot->endTime()) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php require __DIR__ . '/../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
