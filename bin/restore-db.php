<?php

declare(strict_types=1);

// Restauration COMPLÈTE de la base depuis une sauvegarde (#241) :
//   php bin/restore-db.php --list --dir=<dossier>
//   php bin/restore-db.php <sauvegarde.sql.gz> --dir=<dossier> --confirm=<nom exact de la base> [--scratch=<base temporaire>]
// Ordre : vérification du dump, essai à blanc dans la base temporaire (--scratch, vide), dump de l'état ACTUEL (pre-…-avant-restauration),
// puis seulement suppression des tables et import. Sans --confirm exact, rien n'est touché. Le nom de la sauvegarde est un nom de fichier du
// dossier (jamais un chemin). Ensuite : migrations manquantes, contrôle des clés de chiffrement (rien n'est chiffré automatiquement),
// liste des documents de groupes sans fichier / fichiers sans ligne (lecture seule). Aucun identifiant n'est affiché.
// Code de sortie : 0 = restaurée, 1 = échec ou refus.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

use App\Backup\BackupException;
use App\Backup\DatabaseBackup;
use App\Backup\DumpVerifier;
use App\Backup\ProcessDumper;
use App\Backup\ProcessImporter;
use App\Backup\Restore\BackupCatalog;
use App\Backup\Restore\DatabaseRestore;
use App\Backup\Restore\DocumentOrphanReport;
use App\Backup\Restore\RestoreStatus;
use App\Backup\Restore\SchemaTools;
use App\Database\ConnectionFactory;
use App\Messaging\Crypto\MessageKeyFile;
use App\Messaging\Crypto\MessageReencryptor;
use App\Migration\Migrator;
use Symfony\Component\Clock\NativeClock;

$options = getopt('', ['list', 'dir:', 'confirm:', 'scratch:']);
$file = '';
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--')) {
        $file = $argument;
    }
}
$directory = is_string($options['dir'] ?? null) ? $options['dir'] : '';
if ($directory === '' || (!isset($options['list']) && $file === '')) {
    fwrite(STDERR, "Usage : php bin/restore-db.php --list --dir=<dossier>\n        php bin/restore-db.php <sauvegarde.sql.gz> --dir=<dossier> --confirm=<nom de la base> [--scratch=<base temporaire>]\n");
    exit(1);
}

$config = require __DIR__ . '/../config/config.php';
$catalog = new BackupCatalog($directory);

if (isset($options['list'])) {
    foreach ($catalog->list() as $entry) {
        echo sprintf("%s  %s  %s  %d octets\n", $entry->createdAt->format('Y-m-d H:i:s \U\T\C'), $entry->kind, $entry->file, $entry->bytes);
    }
    exit(0);
}

$clock = new NativeClock();
$pdo = (new ConnectionFactory($config['db']))->create();
$scratch = is_string($options['scratch'] ?? null) && $options['scratch'] !== '' ? $options['scratch'] : null;

// Suites, lancées dans le verrou une fois l'import réussi : leurs erreurs n'annulent pas la restauration, elles sont rapportées.
$followUp = static function () use ($pdo, $config): string {
    $notes = [];
    try {
        $applied = (new Migrator($pdo, __DIR__ . '/../database/migrations'))->run();
        $notes[] = $applied === [] ? 'Migrations : à jour.' : 'Migrations appliquées : ' . implode(', ', $applied) . '.';
    } catch (Throwable) {
        $notes[] = 'MIGRATIONS EN ÉCHEC : lancer php bin/migrate.php et lire le message.';
    }
    try {
        $verified = (new MessageReencryptor($pdo, (new MessageKeyFile($config['messages']['key_file']))->cipher()))->verifyReadable();
        $notes[] = "Messages : {$verified} valeur(s) chiffrée(s) se déchiffrent.";
    } catch (Throwable) {
        $notes[] = 'MESSAGES ILLISIBLES : dump d\'avant le chiffrement ? Voir « Restaurer la base » (.claude/deploiement.md) : message-keys.php transition, encrypt-messages.php, check, strict.';
    }
    $orphans = new DocumentOrphanReport($pdo, $config['storage']['group_documents_path']);
    $notes[] = sprintf('Documents de groupes : %d ligne(s) sans fichier, %d fichier(s) sans ligne (aucun supprimé).', count($orphans->missingFiles()), count($orphans->orphanFiles()));

    return implode("\n", $notes);
};

$backup = new DatabaseBackup($directory, (new ProcessDumper($config['db']))->__invoke(...), new DumpVerifier(), $clock);
$restore = new DatabaseRestore(
    $catalog,
    new DumpVerifier(),
    static fn (string $label): string => $backup->run(DatabaseBackup::PRE_DEPLOY, $label)['file'],
    (new ProcessImporter($config['db']))->__invoke(...),
    new SchemaTools($pdo),
    new RestoreStatus((string) $config['restore']['state_dir'], $clock),
    $config['db']['name'],
    $scratch,
    $clock,
    $followUp,
);

try {
    $result = $restore->restore($file, is_string($options['confirm'] ?? null) ? $options['confirm'] : '');
} catch (BackupException|InvalidArgumentException $e) {
    fwrite(STDERR, 'Restauration ÉCHOUÉE : ' . $e->getMessage() . "\n");
    exit(1);
}

echo sprintf("Base restaurée depuis %s (%d tables%s).\n", $file, $result['tables'], $result['rehearsed'] ? ', essai à blanc réussi' : ', SANS essai à blanc');
echo "Dump de sécurité (état d'avant) : {$result['safetyBackup']}\n";

echo $result['notes'] . "\n";
