<?php

declare(strict_types=1);

namespace App\Backup\Restore;

/**
 * #241 : après une restauration, liste les écarts entre group_documents et le dossier de stockage.
 * Lecture seule : ne supprime, n'écrit et ne lit jamais le contenu d'un fichier.
 */
final class DocumentOrphanReport
{
    public function __construct(private readonly \PDO $pdo, private readonly string $storagePath)
    {
    }

    /** @return list<array{id: int, groupId: int, originalName: string}> lignes dont le fichier est absent */
    public function missingFiles(): array
    {
        $statement = $this->pdo->prepare('SELECT id, group_id, original_name, stored_name FROM group_documents ORDER BY id');
        $statement->execute();

        $missing = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (is_file($this->storagePath . '/' . $row['stored_name'])) {
                continue;
            }
            $missing[] = [
                'id' => (int) $row['id'],
                'groupId' => (int) $row['group_id'],
                'originalName' => (string) $row['original_name'],
            ];
        }

        return $missing;
    }

    /** @return list<string> fichiers du dossier qui ne correspondent à aucune ligne, triés */
    public function orphanFiles(): array
    {
        if (!is_dir($this->storagePath)) {
            return [];
        }

        $statement = $this->pdo->prepare('SELECT stored_name FROM group_documents');
        $statement->execute();
        $known = array_flip($statement->fetchAll(\PDO::FETCH_COLUMN));

        $orphans = [];
        foreach (scandir($this->storagePath) ?: [] as $name) {
            if (str_starts_with($name, '.') || !is_file($this->storagePath . '/' . $name) || isset($known[$name])) {
                continue;
            }
            $orphans[] = $name;
        }
        sort($orphans, SORT_STRING);

        return $orphans;
    }
}
