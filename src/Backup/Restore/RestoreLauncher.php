<?php

declare(strict_types=1);

namespace App\Backup\Restore;

use App\Backup\BackupException;

/**
 * Lance `bin/restore-db.php` en processus DÉTACHÉ depuis la page d'administration (#241) : la requête web rend la main aussitôt (la
 * restauration dure plus longtemps qu'une requête et remplace la base dont la page dépend). Sans shell : programme et arguments en
 * tableau, le nom de sauvegarde étant revalidé ici (jamais d'option ni de chemin). `setsid` détache le processus de la requête.
 * La sortie du script va dans un journal en 0600 (noms de fichiers seulement, aucun identifiant).
 */
final class RestoreLauncher
{
    public function __construct(
        private readonly string $phpBinary,
        private readonly string $script,
        private readonly string $backupDirectory,
        private readonly string $productionSchema,
        private readonly ?string $scratchSchema,
        private readonly string $logPath,
        private readonly string $workingDirectory,
    ) {
    }

    /** @throws BackupException */
    public function launch(string $backupFile): void
    {
        if (!BackupCatalog::isBackupName($backupFile)) {
            throw new BackupException('Nom de sauvegarde invalide.');
        }

        $command = ['setsid', $this->phpBinary, $this->script, $backupFile, '--dir=' . $this->backupDirectory, '--confirm=' . $this->productionSchema];
        if ($this->scratchSchema !== null) {
            $command[] = '--scratch=' . $this->scratchSchema;
        }

        $directory = dirname($this->logPath);
        if (!is_dir($directory)) {
            @mkdir($directory, 0o700, true);
        }
        $previous = umask(0o177);
        try {
            $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->logPath, 'a'], 2 => ['file', $this->logPath, 'a']], $pipes, $this->workingDirectory);
        } finally {
            umask($previous);
        }
        if (!is_resource($process)) {
            throw new BackupException('Impossible de lancer la restauration depuis le serveur web.');
        }
        // Pas de proc_close : il attendrait la fin du processus. Le processus détaché poursuit seul.
    }
}
