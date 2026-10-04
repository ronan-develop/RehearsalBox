<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <title>Mon mot de passe — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/auth.css">
</head>
<body>
    <div class="rb-auth-page rb-account-page">
        <div class="rb-auth-card rb-card">
            <h1 class="rb-auth-title">Mon mot de passe</h1>
            <p class="rb-auth-intro">Pour changer de mot de passe, saisissez l'actuel puis le nouveau (8 caractères minimum). Vos autres appareils seront déconnectés.</p>
            <form data-async data-endpoint="/api/auth/change-password" data-method="POST">
                <div class="rb-field">
                    <label for="currentPassword">Mot de passe actuel</label>
                    <input type="password" id="currentPassword" name="currentPassword" class="rb-input" required autocomplete="current-password">
                    <span class="rb-field-error" data-field-error="currentPassword"></span>
                </div>
                <div class="rb-field">
                    <label for="password">Nouveau mot de passe</label>
                    <input type="password" id="password" name="password" class="rb-input" required minlength="8" autocomplete="new-password">
                    <span class="rb-field-error" data-field-error="password"></span>
                </div>
                <div class="rb-field">
                    <label for="passwordConfirmation">Confirmation</label>
                    <input type="password" id="passwordConfirmation" name="passwordConfirmation" class="rb-input" required minlength="8" autocomplete="new-password">
                    <span class="rb-field-error" data-field-error="passwordConfirmation"></span>
                </div>
                <button type="submit" class="rb-btn-primary">Changer le mot de passe</button>
            </form>
        </div>
    </div>
    <?php require __DIR__ . '/../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
