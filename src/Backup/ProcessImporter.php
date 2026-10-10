<?php

declare(strict_types=1);

namespace App\Backup;

/**
 * Importe un dump compressé (gzip) dans une base via le client `mariadb` (#241). Même discipline que ProcessDumper : identifiants par un
 * fichier d'options temporaire en 0600 (ClientOptions), programme lancé sans shell, messages du programme jamais relayés (ils peuvent nommer
 * l'utilisateur). Le dump est décompressé au fil de l'eau et envoyé sur l'entrée standard : jamais chargé en mémoire.
 */
final class ProcessImporter
{
    private const CHUNK = 65536;

    private readonly ClientOptions $options;

    /**
     * @param array{host: string, port: string, name: string, user: string, password: string} $db
     *
     * @throws \InvalidArgumentException configuration incomplète
     */
    public function __construct(array $db, private readonly string $binary = 'mariadb')
    {
        $this->options = new ClientOptions($db);
    }

    /**
     * @throws BackupException               dump illisible, programme introuvable ou import en échec
     * @throws \InvalidArgumentException     nom de base qui n'est pas un identifiant simple
     */
    public function __invoke(string $gzDump, string $database): void
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
            throw new \InvalidArgumentException('Nom de base invalide.');
        }
        $input = $this->openDump($gzDump);

        $optionsPath = $this->options->writeTemporaryFile();
        $errors = tempnam(sys_get_temp_dir(), 'rberr');
        try {
            if ($errors === false) {
                throw new BackupException('Fichier temporaire impossible.');
            }
            $this->run($input, $optionsPath, $errors, $database);
        } finally {
            gzclose($input);
            @unlink($optionsPath);
            if ($errors !== false) {
                @unlink($errors);
            }
        }
    }

    /** @return list<string> programme et arguments ; `--defaults-extra-file` doit être la première option */
    private function arguments(string $optionsPath, string $database): array
    {
        return [$this->binary, '--defaults-extra-file=' . $optionsPath, $database];
    }

    /** @return resource */
    private function openDump(string $gzDump)
    {
        $handle = is_file($gzDump) ? fopen($gzDump, 'rb') : false;
        if ($handle === false) {
            throw new BackupException('Dump illisible.');
        }
        $magic = fread($handle, 2);
        fclose($handle);
        $input = $magic === "\x1f\x8b" ? gzopen($gzDump, 'rb') : false;
        if ($input === false) {
            throw new BackupException('Dump illisible.');
        }

        return $input;
    }

    /** @param resource $input */
    private function run($input, string $optionsPath, string $errors, string $database): void
    {
        $process = @proc_open($this->arguments($optionsPath, $database), [0 => ['pipe', 'r'], 1 => ['file', $errors, 'w'], 2 => ['file', $errors, 'w']], $pipes);
        if (!is_resource($process)) {
            throw new BackupException('Programme d\'import introuvable ou non exécutable.');
        }
        while (!gzeof($input)) {
            $chunk = gzread($input, self::CHUNK);
            if ($chunk === false || $chunk === '') {
                break;
            }
            // Le client peut s'arrêter avant la fin (erreur SQL) : l'écriture échoue alors, on le constate par son code de sortie.
            if (!$this->writeAll($pipes[0], $chunk)) {
                break;
            }
        }
        fclose($pipes[0]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new BackupException("L'import a échoué (code {$code}).");
        }
    }

    /** @param resource $pipe */
    private function writeAll($pipe, string $data): bool
    {
        $length = strlen($data);
        for ($written = 0; $written < $length;) {
            $count = @fwrite($pipe, substr($data, $written));
            if ($count === false || $count === 0) {
                return false;
            }
            $written += $count;
        }

        return true;
    }
}
