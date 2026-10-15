<?php
/**
 * Page d'une demande d'échange de créneau (#462) : la carte du tableau de bord, seule. Rendu par le serveur ; la réponse passe par
 * l'API (<rb-request-card> + availability.js), jamais par un envoi de formulaire. Une fois la demande traitée, la confirmation
 * (`data-exchange-done`) remplace les boutons.
 *
 * @var string $csrfToken
 * @var \App\Dashboard\DashboardExceptionItem $item
 * @var \App\Account\Entity\UserRole $currentUserRole
 */
    $deckPosition = 0;
    $answered = !$item->exception()->isEnAttente();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <title>Demande d'échange — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/dashboard.css">
</head>
<body>
    <div class="rb-exchange-page">
        <?php $backHref = '/'; $backLabel = 'Retour au tableau de bord'; require __DIR__ . '/../partials/back-button.php'; ?>
        <h1>Demande d'échange</h1>
        <div class="rb-exception-deck" data-exception-deck>
            <?php require __DIR__ . '/../dashboard/_request-card-echange.php'; ?>
        </div>
        <p class="rb-exchange-done" data-exchange-done role="status"<?= $answered ? '' : ' hidden' ?>>
            <?= $answered ? 'Cette demande a déjà reçu une réponse.' : 'Cette demande a été traitée.' ?>
            <a href="/">Retour au tableau de bord</a>
        </p>
    </div>

    <?php require __DIR__ . '/../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
