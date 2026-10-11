<?php

declare(strict_types=1);

// Déclaration d'une action faite hors du serveur (#375) : php bin/ops-declare.php offsite|key-saved --base=<dossier de l'installation>
//   offsite    une copie des dumps vient d'être récupérée sur le poste (appelé par bin/fetch-backup.sh)
//   key-saved  la clé de chiffrement vient d'être rangée dans KeePass (à lancer à la main par SSH)
// Seule l'horloge du serveur est enregistrée (shared/ops-declarations.json, 0600). Ligne de commande uniquement : la page est en lecture seule.
// Code de sortie : 0 = enregistré, 1 = refusé ou impossible.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

use App\Operations\Backup\Declarations;
use App\Operations\OpsPaths;
use Symfony\Component\Clock\NativeClock;

$what = $argv[1] ?? '';
$options = getopt('', ['base:']);
$base = is_string($options['base'] ?? null) ? $options['base'] : '';
if ($base === '' || !in_array($what, [Declarations::OFFSITE, Declarations::KEY_SAVED], true)) {
    fwrite(STDERR, "Usage : php bin/ops-declare.php offsite|key-saved --base=<dossier de l'installation>\n");
    exit(1);
}

try {
    (new Declarations((new OpsPaths($base))->declarations(), new NativeClock()))->declare($what);
} catch (Throwable) {
    fwrite(STDERR, "Déclaration impossible.\n");
    exit(1);
}
echo "Déclaré : {$what}\n";
