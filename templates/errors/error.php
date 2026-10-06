<?php
/**
 * Page d'erreur unique (#259), rendue par App\Http\ErrorPage pour tous les codes.
 *
 * @var int    $status code HTTP
 * @var string $title  titre fixe (jamais le message d'une exception)
 * @var string $text   explication fixe
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($title) ?> — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/error.css">
</head>
<body class="rb-error-page">
    <main class="rb-error-card rb-card">
        <p class="rb-error-code" aria-hidden="true"><?= e((string) $status) ?></p>
        <span class="rb-error-logo">#B<span>27</span></span>
        <h1><?= e($title) ?></h1>
        <p class="rb-error-text"><?= e($text) ?></p>
        <div class="rb-error-actions">
            <a href="/" class="rb-btn rb-btn-primary">Retour à l’accueil</a>
        </div>
    </main>
</body>
</html>
