<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <title>Nouveau mot de passe — RehearsalBox</title>
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
            <?php if (($token ?? '') === ''): ?>
                <p class="rb-auth-intro">Ce lien de réinitialisation est invalide ou incomplet.</p>
                <p class="rb-auth-link"><a href="/forgot-password">Demander un nouveau lien</a></p>
            <?php else: ?>
                <p class="rb-auth-intro">Choisissez un nouveau mot de passe (10 caractères minimum).</p>
                <rb-async-form endpoint="/api/auth/reset-password" method="POST"><form>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <div class="rb-field">
                        <label for="password">Nouveau mot de passe</label>
                        <input type="password" id="password" name="password" class="rb-input" required minlength="10" autocomplete="new-password">
                        <span class="rb-field-error" data-field-error="password"></span>
                    </div>
                    <button type="submit" class="rb-btn-primary">Enregistrer</button>
                </form></rb-async-form>
                <p class="rb-auth-link"><a href="/forgot-password">Demander un nouveau lien</a></p>
            <?php endif; ?>
        </div>
    </div>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
