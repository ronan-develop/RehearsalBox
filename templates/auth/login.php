<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <title>Connexion — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/auth.css">
</head>
<body>
    <div class="rb-auth-page">
        <div class="rb-auth-card">
            <h1>RehearsalBox</h1>
            <form data-async data-endpoint="/api/auth/login" data-method="POST">
                <div class="rb-field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required autocomplete="email">
                    <span class="rb-field-error" data-field-error="email"></span>
                </div>
                <div class="rb-field">
                    <label for="password">Mot de passe</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                    <span class="rb-field-error" data-field-error="password"></span>
                </div>
                <button type="submit" class="rb-auth-submit">Se connecter</button>
            </form>
        </div>
    </div>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
