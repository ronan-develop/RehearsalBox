<?php

declare(strict_types=1);

namespace App\Backup\Restore;

/**
 * Catalogue des sauvegardes de la base (#241) : lit le dossier de sauvegarde et ne retient que les fichiers réguliers
 * au motif `db-` / `pre-` + horodatage de 14 chiffres + `.sql.gz`. Un dossier, un lien symbolique, un dump `.partial`,
 * un fichier inconnu ou une date invalide sont ignorés. Ne touche jamais au disque hors de ce dossier.
 */
final class BackupCatalog
{
    private const PATTERN = '/^(db|pre)-(\d{14})([A-Za-z0-9._-]*)\.sql\.gz$/';

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Plus récente d'abord (horodatage, puis nom décroissant). Dossier absent => liste vide.
     *
     * @return list<BackupEntry>
     */
    public function list(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $entries = [];
        foreach (scandir($this->directory) as $name) {
            $entry = $this->entryFor($name);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        usort($entries, static fn (BackupEntry $a, BackupEntry $b): int => $b->createdAt <=> $a->createdAt ?: strcmp($b->file, $a->file));

        return $entries;
    }

    /** Le nom a la forme d'une sauvegarde (jamais d'option, de chemin ni d'espace) : sert aussi de garde-fou avant de le passer à un processus. */
    public static function isBackupName(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1;
    }

    public function pathOf(BackupEntry $entry): string
    {
        return $this->directory . '/' . $entry->file;
    }

    /** Renvoie l'entrée dont le nom est exactement $file, ou null (chemin, nom inconnu, absent du dossier). */
    public function find(string $file): ?BackupEntry
    {
        foreach ($this->list() as $entry) {
            if ($entry->file === $file) {
                return $entry;
            }
        }

        return null;
    }

    private function entryFor(string $name): ?BackupEntry
    {
        if (!self::isBackupName($name) || preg_match(self::PATTERN, $name, $parts) !== 1) {
            return null;
        }

        $path = $this->directory . '/' . $name;
        if (!is_file($path) || is_link($path)) {
            return null;
        }

        $createdAt = \DateTimeImmutable::createFromFormat('!YmdHis', $parts[2], new \DateTimeZone('UTC'));
        if ($createdAt === false || $createdAt->format('YmdHis') !== $parts[2]) {
            return null;
        }

        $isDaily = $parts[1] === 'db';

        return new BackupEntry(
            file: $name,
            kind: $isDaily ? 'daily' : 'pre-deploy',
            createdAt: $createdAt,
            bytes: (int) filesize($path),
            label: $isDaily ? '' : $parts[2] . $parts[3],
        );
    }
}
