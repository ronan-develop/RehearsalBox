<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <title>Mon compte — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/auth.css">
</head>
<body>
    <div class="rb-auth-page rb-account-page">
        <div class="rb-account-stack">
        <div class="rb-auth-card rb-card">
            <h1 class="rb-auth-title">Mon compte</h1>
            <h2 class="rb-account-section-title">Mes informations</h2>
            <p class="rb-auth-intro">Votre adresse e-mail est votre identifiant de connexion : <strong><?= e($email) ?></strong>.</p>
            <form data-async data-endpoint="/api/account/profile" data-method="PATCH">
                <div class="rb-field">
                    <label for="displayName">Nom affiché</label>
                    <input type="text" id="displayName" name="displayName" class="rb-input" value="<?= e($displayName) ?>" required maxlength="100" autocomplete="name">
                    <span class="rb-field-error" data-field-error="displayName"></span>
                </div>
                <button type="submit" class="rb-btn-primary">Enregistrer mon nom</button>
            </form>
        </div>
        <div class="rb-auth-card rb-card">
            <h2 class="rb-account-section-title">Notifications par e-mail</h2>
            <p class="rb-auth-intro">Quand quelqu'un vous <strong>mentionne</strong> dans une conversation, un e-mail vous prévient (jamais le contenu du message), au plus un toutes les 24 heures par conversation. Vous pouvez vous en désinscrire à tout moment.</p>
            <form data-async data-endpoint="/api/account/notifications" data-method="PATCH">
                <fieldset class="rb-field rb-radio-group">
                    <legend>Recevoir un e-mail quand on me mentionne</legend>
                    <label class="rb-radio"><input type="radio" name="emailNotifications" value="1"<?= ($emailNotifications ?? true) ? ' checked' : '' ?>> Oui</label>
                    <label class="rb-radio"><input type="radio" name="emailNotifications" value="0"<?= ($emailNotifications ?? true) ? '' : ' checked' ?>> Non</label>
                    <span class="rb-field-error" data-field-error="emailNotifications"></span>
                </fieldset>
                <button type="submit" class="rb-btn-primary">Enregistrer</button>
            </form>
        </div>
        <div class="rb-auth-card rb-card">
            <h2 class="rb-account-section-title">Adresse e-mail</h2>
            <p class="rb-auth-intro">Un lien de confirmation (valable 1 heure) sera envoyé à la <strong>nouvelle</strong> adresse, et l'ancienne sera prévenue. Vous serez ensuite déconnecté de vos appareils et vous vous reconnecterez avec la nouvelle adresse.</p>
            <form data-async data-endpoint="/api/account/email" data-method="PATCH">
                <div class="rb-field">
                    <label for="newEmail">Nouvelle adresse e-mail</label>
                    <input type="email" id="newEmail" name="email" class="rb-input" required maxlength="190" autocomplete="email">
                    <span class="rb-field-error" data-field-error="email"></span>
                </div>
                <div class="rb-field">
                    <label for="emailCurrentPassword">Mot de passe actuel</label>
                    <input type="password" id="emailCurrentPassword" name="currentPassword" class="rb-input" required autocomplete="current-password">
                    <span class="rb-field-error" data-field-error="currentPassword"></span>
                </div>
                <button type="submit" class="rb-btn-primary">Envoyer le lien de confirmation</button>
            </form>
        </div>
        <div class="rb-auth-card rb-card">
            <h2 class="rb-account-section-title">Mot de passe</h2>
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
    </div>
    <?php require __DIR__ . '/../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
