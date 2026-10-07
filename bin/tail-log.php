<?php

declare(strict_types=1);

// Les dernières lignes du journal (#193), sans chercher le fichier à la main.
//   Usage : php bin/tail-log.php [app|cron] [nombre de lignes, 50 par défaut]
// Le journal ne contient aucune donnée personnelle (identifiants numériques et classes d'erreurs seulement).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$config = require __DIR__ . '/../config/config.php';

$which = $argv[1] ?? 'app';
if (!in_array($which, ['app', 'cron'], true)) {
    fwrite(STDERR, "Usage : php bin/tail-log.php [app|cron] [lignes]\n");
    exit(2);
}
$count = max(1, min(1000, (int) ($argv[2] ?? 50)));
$file = $config['logging'][$which === 'app' ? 'path' : 'cron_path'];

if (!is_file($file)) {
    echo "Aucun journal « {$which} » pour l'instant ({$file} n'existe pas encore).\n";
    exit(0);
}

$lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
echo implode("\n", array_slice($lines, -$count)), "\n";
