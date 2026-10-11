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
            <?php $backHref = '/'; $backLabel = 'Retour aux disponibilités'; require __DIR__ . '/../partials/back-button.php'; ?>
            <h1 class="rb-auth-title">Mon compte</h1>
            <h2 class="rb-account-section-title">Mes informations</h2>
            <p class="rb-auth-intro">Votre adresse e-mail est votre identifiant de connexion : <strong><?= e($email) ?></strong>.</p>
            <rb-async-form endpoint="/api/account/profile" method="PATCH"><form>
                <div class="rb-field">
                    <label for="displayName">Nom affiché</label>
                    <input type="text" id="displayName" name="displayName" class="rb-input" value="<?= e($displayName) ?>" required maxlength="100" autocomplete="name">
                    <span class="rb-field-error" data-field-error="displayName"></span>
                </div>
                <button type="submit" class="rb-btn-primary">Enregistrer mon nom</button>
            </form></rb-async-form>
        </div>
        <div class="rb-auth-card rb-card">
            <h2 class="rb-account-section-title">Adresse e-mail</h2>
            <p class="rb-auth-intro">Un lien de confirmation (valable 1 heure) sera envoyé à la <strong>nouvelle</strong> adresse, et l'ancienne sera prévenue. Vous serez ensuite déconnecté de vos appareils et vous vous reconnecterez avec la nouvelle adresse.</p>
            <rb-async-form endpoint="/api/account/email" method="PATCH"><form>
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
            </form></rb-async-form>
        </div>
        <div class="rb-auth-card rb-card">
            <h2 class="rb-account-section-title">Mot de passe</h2>
            <p class="rb-auth-intro">Pour changer de mot de passe, saisissez l'actuel puis le nouveau (10 caractères minimum). Vos autres appareils seront déconnectés.</p>
            <rb-async-form endpoint="/api/auth/change-password" method="POST"><form>
                <div class="rb-field">
                    <label for="currentPassword">Mot de passe actuel</label>
                    <input type="password" id="currentPassword" name="currentPassword" class="rb-input" required autocomplete="current-password">
                    <span class="rb-field-error" data-field-error="currentPassword"></span>
                </div>
                <div class="rb-field">
                    <label for="password">Nouveau mot de passe</label>
                    <input type="password" id="password" name="password" class="rb-input" required minlength="10" autocomplete="new-password">
                    <span class="rb-field-error" data-field-error="password"></span>
                </div>
                <div class="rb-field">
                    <label for="passwordConfirmation">Confirmation</label>
                    <input type="password" id="passwordConfirmation" name="passwordConfirmation" class="rb-input" required minlength="10" autocomplete="new-password">
                    <span class="rb-field-error" data-field-error="passwordConfirmation"></span>
                </div>
                <button type="submit" class="rb-btn-primary">Changer le mot de passe</button>
            </form></rb-async-form>
        </div>
        <div class="rb-auth-card rb-card">
            <h2 class="rb-account-section-title">Mes données</h2>
            <p class="rb-auth-intro">Téléchargez ce que RehearsalBox garde de vous : votre profil, vos groupes, les messages que vous avez écrits et vos demandes de réservation.</p>
            <a class="rb-btn" href="/api/account/export" download>Télécharger mes données (JSON)</a>
        </div>
        <div class="rb-auth-card rb-card" data-account-deletion>
            <h2 class="rb-account-section-title">Supprimer mon compte</h2>
            <p class="rb-auth-intro">Votre nom, votre adresse e-mail et votre mot de passe sont <strong>effacés</strong> et vous quittez vos groupes. Les messages que vous avez envoyés restent dans les conversations des autres, sous le nom « Ancien membre ». <strong>Cette action est définitive.</strong> Un gestionnaire seul dans son groupe, ou un administrateur, doit d'abord passer la main.</p>
            <rb-async-form endpoint="/api/account" method="DELETE"><form>
                <div class="rb-field">
                    <label for="deletionPassword">Votre mot de passe</label>
                    <input type="password" id="deletionPassword" name="password" class="rb-input" required autocomplete="current-password">
                    <span class="rb-field-error" data-field-error="currentPassword"></span>
                </div>
                <div class="rb-field">
                    <label for="deletionConfirmation">Tapez SUPPRIMER pour confirmer</label>
                    <input type="text" id="deletionConfirmation" name="confirmation" class="rb-input" required autocomplete="off" autocapitalize="characters">
                    <span class="rb-field-error" data-field-error="confirmation"></span>
                </div>
                <button type="submit" class="rb-btn rb-btn-danger">Supprimer mon compte</button>
            </form></rb-async-form>
        </div>
        </div>
    </div>
    <?php require __DIR__ . '/../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
