<?php

declare(strict_types=1);

namespace App\Backup;

use Symfony\Component\Clock\ClockInterface;

/**
 * Sauvegarde de la base (#167) : écrit le dump dans un fichier PROVISOIRE, le vérifie (complet, avec ses tables, avec sa ligne de fin),
 * puis le renomme (atomique) sous son nom définitif, en 0600 dans un dossier en 0700. Ce n'est qu'APRÈS cette réussite que la rotation
 * supprime les plus anciennes sauvegardes du même type : un dump raté ne fait jamais disparaître une bonne sauvegarde. Un fichier
 * existant n'est jamais écrasé. Aucun identifiant ni contenu de la base dans les messages.
 *
 * Deux types : `daily` (`db-<horodatage>.sql.gz`, 14 gardées) et `pre-deploy` (`pre-<release>.sql.gz`, 7 gardées).
 */
final class DatabaseBackup
{
    public const DAILY = 'daily';
    public const PRE_DEPLOY = 'pre-deploy';

    private const KINDS = [
        self::DAILY => ['prefix' => 'db', 'keep' => 14],
        self::PRE_DEPLOY => ['prefix' => 'pre', 'keep' => 7],
    ];

    /** @param \Closure(string): void $dumper écrit le dump compressé dans le fichier reçu (ProcessDumper) ; lève BackupException en cas d'échec */
    public function __construct(
        private readonly string $directory,
        private readonly \Closure $dumper,
        private readonly DumpVerifier $verifier,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param string|null $label identifiant de la release (obligatoire pour `pre-deploy`, interdit ailleurs) : [A-Za-z0-9._-], commence par 14 chiffres
     *
     * @return array{file: string, bytes: int, tables: int, removed: list<string>}
     *
     * @throws BackupException
     */
    public function run(string $kind, ?string $label = null): array
    {
        $settings = self::KINDS[$kind] ?? throw new BackupException('Type de sauvegarde inconnu.');
        $name = $this->nameFor($settings['prefix'], $kind, $label);

        $this->prepareDirectory();
        $final = $this->directory . '/' . $name;
        if (file_exists($final)) {
            throw new BackupException('Cette sauvegarde existe déjà : elle n\'est jamais écrasée.');
        }

        $partial = $final . '.partial';
        $previous = umask(0o177);
        try {
            ($this->dumper)($partial);
            $tables = $this->verifier->verify($partial);
            chmod($partial, 0o600);
            if (!rename($partial, $final)) {
                throw new BackupException('Sauvegarde impossible à finaliser.');
            }
        } catch (\Throwable $e) {
            @unlink($partial);
            throw $e;
        } finally {
            umask($previous);
        }

        return ['file' => $name, 'bytes' => (int) filesize($final), 'tables' => $tables, 'removed' => $this->rotate($settings['prefix'], $settings['keep'])];
    }

    private function nameFor(string $prefix, string $kind, ?string $label): string
    {
        if ($kind === self::DAILY) {
            if ($label !== null) {
                throw new BackupException('Un dump quotidien ne porte pas d\'étiquette.');
            }

            return $prefix . '-' . $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('YmdHis') . '.sql.gz';
        }
        if ($label === null || preg_match('/^\d{14}[A-Za-z0-9._-]*$/', $label) !== 1) {
            throw new BackupException('Étiquette de release invalide.');
        }

        return $prefix . '-' . $label . '.sql.gz';
    }

    private function prepareDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
            throw new BackupException('Dossier de sauvegarde impossible à créer.');
        }
        chmod($this->directory, 0o700);
    }

    /** @return list<string> */
    private function rotate(string $prefix, int $keep): array
    {
        $files = array_map('basename', glob($this->directory . '/*') ?: []);
        $removed = [];
        foreach ((new BackupRetention($prefix, $keep))->expired($files) as $old) {
            if (@unlink($this->directory . '/' . $old)) {
                $removed[] = $old;
            }
        }

        return $removed;
    }
}
