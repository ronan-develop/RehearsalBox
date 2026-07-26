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

        <?php if ($planningSlots !== []): ?>
            <?php
            $renderPlanningCard = static function ($requestableSlot) {
                $slot = $requestableSlot->slot();
                ?>
                <article class="rb-planning-card" role="button" tabindex="0" data-contact-group-id="<?= e((string) $requestableSlot->groupId()) ?>" data-contact-group-name="<?= e($requestableSlot->groupName()) ?>">
                    <span class="rb-planning-card-tape" aria-hidden="true"></span>
                    <div class="rb-planning-card-shape">
                        <h3 class="rb-planning-card-group"><?= e($requestableSlot->groupName()) ?></h3>
                        <p class="rb-planning-card-weekday"><?= e(formatWeekday($slot->weekday())) ?></p>
                        <p class="rb-planning-card-time"><?= e(formatTime($slot->startTime())) ?> – <?= e(formatTime($slot->endTime())) ?></p>
                    </div>
                </article>
                <?php
            };
            ?>
            <section class="rb-planning-section">
                <h2>Planning des créneaux fixes</h2>
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

        <?php if ($dashboardExceptions !== []): ?>
            <section class="rb-exception-deck" data-exception-deck>
                <?php foreach ($dashboardExceptions as $deckPosition => $item): ?>
                    <?php
                    $exception = $item->exception();
                    $isRecue = $item->direction() === \App\Entity\Enum\ExceptionDirection::Recue;
                    ?>
                    <article class="rb-slot-card rb-card rb-exception-card" data-exception-id="<?= e((string) $exception->id()) ?>"
                             style="--deck-index: <?= e((string) $deckPosition) ?>">
                        <p class="rb-badge rb-badge-direction"><?= $isRecue ? 'Reçue' : 'Envoyée' ?></p>
                        <p class="rb-badge rb-badge-status"><?= e(formatExceptionStatus($exception->status())) ?></p>
                        <p class="rb-slot-card-date"><?= e($exception->occurrenceDate()->format('d/m/Y')) ?></p>
                        <?php if ($exception->requestReason() !== null): ?>
                            <p class="rb-slot-card-reason"><?= e($exception->requestReason()) ?></p>
                        <?php endif; ?>
                        <?php if ($isRecue && $exception->isEnAttente()): ?>
                            <button type="button" class="rb-btn rb-btn-primary" data-respond-button data-accepted="true"
                                    data-exception-id="<?= e((string) $exception->id()) ?>">
                                Accepter
                            </button>
                            <button type="button" class="rb-btn rb-btn-danger" data-respond-button data-accepted="false"
                                    data-exception-id="<?= e((string) $exception->id()) ?>">
                                Refuser
                            </button>
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
                                <button type="submit" class="rb-btn rb-btn-primary">Modifier</button>
                            </form>
                            <button type="button" class="rb-btn rb-btn-danger" data-cancel-button
                                    data-exception-id="<?= e((string) $exception->id()) ?>">
                                Annuler
                            </button>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <div class="rb-modal-overlay" data-contact-modal-overlay hidden>
            <div class="rb-modal rb-card" role="dialog" aria-modal="true" aria-labelledby="contact-modal-title">
                <h2 id="contact-modal-title" data-contact-modal-title>Contacter le groupe</h2>
                <form data-contact-form>
                    <input type="hidden" name="groupId" data-contact-group-id-input>
                    <div class="rb-field">
                        <label for="contact-message">Message</label>
                        <textarea id="contact-message" name="message" class="rb-input" rows="4" required></textarea>
                    </div>
                    <div class="rb-modal-actions">
                        <button type="button" class="rb-btn" data-contact-modal-cancel>Annuler</button>
                        <button type="submit" class="rb-btn rb-btn-primary">Envoyer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php require __DIR__ . '/../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
