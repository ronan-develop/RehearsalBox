<?php

declare(strict_types=1);

// Sauvegarde de la base (#167) : php bin/backup-db.php --kind=daily|pre-deploy --dir=<dossier> [--label=<release>] [--skip-if-empty]
//   daily       dump quotidien `db-<horodatage UTC>.sql.gz`, 14 gardés (tâche planifiée, voir .claude/deploiement.md)
//   pre-deploy  dump d'avant déploiement `pre-<release>.sql.gz` (--label obligatoire), 7 gardés ; lancé par bin/deploy.sh
// Le dump est écrit dans un fichier provisoire, vérifié (tables, ligne de fin), puis renommé ; la rotation ne passe qu'après une réussite.
// Dossier en 0700, fichiers en 0600, hors racine web. Identifiants lus dans config.local.php et transmis par un fichier d'options
// temporaire : jamais dans les arguments, la sortie ni les journaux. Code de sortie : 0 = succès (ou base vide avec --skip-if-empty), 1 = échec.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

use App\Backup\BackupException;
use App\Backup\DatabaseBackup;
use App\Backup\DumpVerifier;
use App\Backup\ProcessDumper;
use App\Database\ConnectionFactory;
use Symfony\Component\Clock\NativeClock;

$options = getopt('', ['kind:', 'dir:', 'label:', 'skip-if-empty']);
$kind = is_string($options['kind'] ?? null) ? $options['kind'] : '';
$directory = is_string($options['dir'] ?? null) ? $options['dir'] : '';
$label = is_string($options['label'] ?? null) ? $options['label'] : null;
if ($kind === '' || $directory === '') {
    fwrite(STDERR, "Usage : php bin/backup-db.php --kind=daily|pre-deploy --dir=<dossier> [--label=<release>] [--skip-if-empty]\n");
    exit(1);
}

$config = require __DIR__ . '/../config/config.php';

try {
    if (isset($options['skip-if-empty'])) {
        $pdo = (new ConnectionFactory($config['db']))->create();
        if ((int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn() === 0) {
            echo "Base vide : pas de sauvegarde nécessaire.\n";
            exit(0);
        }
    }

    $backup = new DatabaseBackup($directory, (new ProcessDumper($config["db"]))->__invoke(...), new DumpVerifier(), new NativeClock());
    $result = $backup->run($kind, $label);
} catch (BackupException|InvalidArgumentException $e) {
    fwrite(STDERR, 'Sauvegarde ÉCHOUÉE : ' . $e->getMessage() . "\n");
    exit(1);
}

echo sprintf("Sauvegarde : %s (%d octets, %d tables).\n", $result['file'], $result['bytes'], $result['tables']);
foreach ($result['removed'] as $removed) {
    echo "Supprimée (rotation) : {$removed}\n";
}
