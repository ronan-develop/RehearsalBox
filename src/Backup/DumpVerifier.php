<?php

declare(strict_types=1);

namespace App\Backup;

/** Contrôle qu'un dump compressé est complet avant de le garder : sinon une sauvegarde tronquée passerait pour bonne. */
final class DumpVerifier
{
    /** @return int nombre de tables (instructions CREATE TABLE) du dump
     *  @throws BackupException */
    public function verify(string $gzPath): int
    {
        if (!is_file($gzPath) || !is_readable($gzPath) || !$this->isGzip($gzPath)) {
            throw new BackupException('Dump illisible.');
        }

        $handle = @gzopen($gzPath, 'rb');
        if ($handle === false) {
            throw new BackupException('Dump illisible.');
        }

        // Lecture ligne à ligne : on garde seulement le compteur et la dernière ligne non vide.
        $tables = 0;
        $bytes = 0;
        $lastLine = '';
        while (($line = gzgets($handle)) !== false) {
            $bytes += strlen($line);
            if (str_starts_with($line, 'CREATE TABLE')) {
                ++$tables;
            }
            $trimmed = rtrim($line);
            if ($trimmed !== '') {
                $lastLine = $trimmed;
            }
        }
        // gzgets renvoie false aussi en cas d'erreur de décompression : seule la fin de fichier est normale.
        $corrupted = !gzeof($handle);
        gzclose($handle);

        if ($corrupted) {
            throw new BackupException('Dump illisible.');
        }
        if ($bytes === 0) {
            throw new BackupException('Dump vide.');
        }
        if ($tables === 0) {
            throw new BackupException('Dump sans table.');
        }
        if (!str_starts_with($lastLine, '-- Dump completed')) {
            throw new BackupException('Dump tronqué (fin absente).');
        }

        return $tables;
    }

    /** Vérifie la signature gzip (0x1f 0x8b) : zlib lirait aussi un texte brut sans erreur. */
    private function isGzip(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $magic = fread($handle, 2);
        fclose($handle);

        return $magic === "\x1f\x8b";
    }
}
