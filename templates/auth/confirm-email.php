<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <title>Confirmer ma nouvelle adresse — RehearsalBox</title>
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
                <p class="rb-auth-intro">Vous avez demandé à utiliser cette adresse e-mail comme identifiant de connexion. En confirmant, vous serez déconnecté de vos appareils et devrez vous reconnecter avec cette nouvelle adresse.</p>
                <rb-async-form endpoint="/api/account/email/confirm" method="POST"><form>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <button type="submit" class="rb-btn-primary">Confirmer ma nouvelle adresse</button>
                </form></rb-async-form>
                <p class="rb-auth-confirmation" data-confirmation hidden>Adresse modifiée. Vous pouvez maintenant vous connecter avec votre nouvelle adresse e-mail.</p>
                <p class="rb-auth-link"><a href="/login">Retour à la connexion</a></p>
            <?php endif; ?>
        </div>
    </div>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
