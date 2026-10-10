<?php

declare(strict_types=1);

namespace App\Backup;

/**
 * Lance `mariadb-dump` et écrit sa sortie compressée (gzip) dans un fichier (#167). Les identifiants passent par un fichier d'options
 * TEMPORAIRE en 0600, supprimé après usage : jamais dans les arguments (visibles dans la liste des processus), jamais dans l'environnement,
 * jamais dans un message d'erreur. Le programme est lancé sans shell (arguments en tableau) : aucune injection possible par un mot de passe.
 * Connexion en utf8mb4 : le serveur est en latin1 par défaut, le dump doit garder accents et emojis des messages.
 */
final class ProcessDumper
{
    private const CHUNK = 65536;

    private readonly ClientOptions $options;

    /**
     * @param array{host: string, port: string, name: string, user: string, password: string} $db
     *
     * @throws \InvalidArgumentException configuration incomplète
     */
    public function __construct(private readonly array $db, private readonly string $binary = 'mariadb-dump')
    {
        $this->options = new ClientOptions($db);
    }

    /** @throws BackupException */
    public function __invoke(string $destination): void
    {
        $options = $this->options->writeTemporaryFile();
        try {
            $this->run($options, $destination);
        } finally {
            @unlink($options);
        }
    }

    /** Contenu du fichier d'options (voir ClientOptions). */
    public function optionsFile(): string
    {
        return $this->options->contents();
    }

    /**
     * @return list<string> programme et arguments ; `--defaults-extra-file` doit être la première option
     */
    public function arguments(string $optionsPath): array
    {
        return [$this->binary, '--defaults-extra-file=' . $optionsPath, '--single-transaction', '--routines', '--no-tablespaces', $this->db['name']];
    }

    private function run(string $optionsPath, string $destination): void
    {
        $errors = tempnam(sys_get_temp_dir(), 'rberr'); // la sortie d'erreur du programme n'est jamais relayée (elle peut nommer l'utilisateur)
        if ($errors === false) {
            throw new BackupException('Fichier temporaire impossible.');
        }
        try {
            $process = @proc_open($this->arguments($optionsPath), [1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']], $pipes);
            if (!is_resource($process)) {
                throw new BackupException('Programme de dump introuvable ou non exécutable.');
            }
            $output = gzopen($destination, 'wb6');
            if ($output === false) {
                proc_close($process);
                throw new BackupException('Écriture de la sauvegarde impossible.');
            }
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], self::CHUNK);
                if ($chunk !== false && $chunk !== '') {
                    gzwrite($output, $chunk);
                }
            }
            fclose($pipes[1]);
            gzclose($output);
            $code = proc_close($process);
            if ($code !== 0) {
                throw new BackupException("Le dump a échoué (code {$code}).");
            }
        } finally {
            @unlink($errors);
        }
    }
}
