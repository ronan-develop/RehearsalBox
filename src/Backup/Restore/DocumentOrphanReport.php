<?php

declare(strict_types=1);

namespace App\Backup\Restore;

/**
 * #241 : après une restauration, liste les écarts entre une table de fichiers (documents de groupe, relevés de compte #362) et son dossier de
 * stockage. Lecture seule : ne supprime, n'écrit et ne lit jamais le contenu d'un fichier. La table et les colonnes viennent des constantes
 * ci-dessous, jamais d'une valeur extérieure.
 */
final class DocumentOrphanReport
{
    /** Documents de groupe : table, colonne du rattachement, clé rendue. */
    public const GROUP_DOCUMENTS = ['group_documents', 'group_id', 'groupId'];
    /** Relevés de compte de l'association (#362). */
    public const ACCOUNTING_STATEMENTS = ['accounting_statements', 'fiscal_year_id', 'fiscalYearId'];

    /** @param array{0: string, 1: string, 2: string} $source l'une des constantes GROUP_DOCUMENTS ou ACCOUNTING_STATEMENTS */
    public function __construct(private readonly \PDO $pdo, private readonly string $storagePath, private readonly array $source = self::GROUP_DOCUMENTS)
    {
    }

    /** @return list<array<string, int|string>> lignes dont le fichier est absent : `id`, la clé de rattachement de la source (`groupId`, `fiscalYearId`) et `originalName` */
    public function missingFiles(): array
    {
        [$table, $ownerColumn, $ownerKey] = $this->source;
        $statement = $this->pdo->prepare("SELECT id, {$ownerColumn} AS owner_id, original_name, stored_name FROM {$table} ORDER BY id");
        $statement->execute();

        $missing = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (is_file($this->storagePath . '/' . $row['stored_name'])) {
                continue;
            }
            $missing[] = [
                'id' => (int) $row['id'],
                $ownerKey => (int) $row['owner_id'],
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

        $statement = $this->pdo->prepare("SELECT stored_name FROM {$this->source[0]}");
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
