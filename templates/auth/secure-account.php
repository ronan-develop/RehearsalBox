<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <title>Sécuriser mon compte — RehearsalBox</title>
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
                <p class="rb-auth-intro">Ce lien est invalide ou incomplet.</p>
                <p class="rb-auth-link"><a href="/login">Retour à la connexion</a></p>
            <?php else: ?>
                <p class="rb-auth-intro">Vous n'êtes pas à l'origine du changement de mot de passe ? En confirmant, votre compte sera verrouillé, toutes les sessions seront fermées et un lien vous sera envoyé par e-mail pour choisir un nouveau mot de passe.</p>
                <rb-async-form endpoint="/api/auth/secure-account" method="POST"><form>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <button type="submit" class="rb-btn-primary">Ce n'est pas moi : sécuriser mon compte</button>
                </form></rb-async-form>
                <p class="rb-auth-confirmation" data-confirmation hidden>Compte sécurisé. Un e-mail vous a été envoyé avec un lien pour choisir un nouveau mot de passe.</p>
                <p class="rb-auth-link"><a href="/login">Retour à la connexion</a></p>
            <?php endif; ?>
        </div>
    </div>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
