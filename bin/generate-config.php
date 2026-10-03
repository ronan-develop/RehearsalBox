<?php

declare(strict_types=1);

// Usage (sur le serveur) : php bin/generate-config.php <chemin-config.local.php>
// Lit sur STDIN des lignes NOM=<valeur encodée en base64> et écrit le fichier en 0600.
// Rien n'est affiché : ni valeur, ni contenu.

require __DIR__ . '/../vendor/autoload.php';

use App\Deploy\LocalConfigGenerator;

$target = $argv[1] ?? null;
if ($target === null) {
    fwrite(STDERR, "Usage : php bin/generate-config.php <chemin>\n");
    exit(2);
}

$env = [];
foreach (preg_split('/\R/', (string) stream_get_contents(STDIN)) ?: [] as $line) {
    if (!str_contains($line, '=')) {
        continue;
    }
    [$name, $encoded] = explode('=', $line, 2);
    $decoded = base64_decode($encoded, true);
    if ($decoded === false) {
        fwrite(STDERR, "Valeur illisible pour {$name}\n");
        exit(1);
    }
    $env[$name] = $decoded;
}

try {
    (new LocalConfigGenerator())->write($target, $env);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "Configuration écrite (0600).\n";
