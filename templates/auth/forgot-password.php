<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <title>Mot de passe oublié — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/auth.css">
</head>
<body>
    <div class="rb-auth-page">
        <div class="rb-auth-card rb-card">
            <div class="rb-auth-logo-wrap rb-stone-panel">
                <span class="rb-auth-logo">#B<span>27</span></span>
                <p class="rb-auth-tagline">Local</p>
            </div>
            <p class="rb-auth-intro">Saisissez l'adresse e-mail de votre compte : nous vous enverrons un lien pour choisir un nouveau mot de passe.</p>
            <rb-async-form endpoint="/api/auth/forgot-password" method="POST"><form>
                <div class="rb-field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="rb-input" required autocomplete="email">
                    <span class="rb-field-error" data-field-error="email"></span>
                </div>
                <button type="submit" class="rb-btn-primary">Envoyer le lien</button>
            </form></rb-async-form>
            <p class="rb-auth-confirmation" data-confirmation hidden>Si un compte existe avec cette adresse, un e-mail vient d'être envoyé. Le lien est valable 1 heure.</p>
            <p class="rb-auth-link"><a href="/login">Retour à la connexion</a></p>
        </div>
    </div>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
