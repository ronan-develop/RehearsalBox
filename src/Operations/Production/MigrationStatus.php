<?php

declare(strict_types=1);

namespace App\Operations\Production;

/**
 * Migrations de la base vues depuis la release servie (#375) : les dernières appliquées et celles du dossier qui ne le sont pas encore.
 * Lecture seule. Base illisible ou table du journal absente : null (« inconnu »), jamais une exception ; des noms de fichiers seulement.
 */
final class MigrationStatus
{
    public function __construct(private readonly ?\PDO $pdo, private readonly string $directory)
    {
    }

    /** @return array{applied: list<array{name: string, at: string}>, pending: list<string>}|null */
    public function status(): ?array
    {
        if ($this->pdo === null) {
            return null;
        }

        try {
            $rows = $this->pdo->query('SELECT migration, applied_at FROM migrations_log ORDER BY id DESC')->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return null;
        }

        $done = array_map(static fn (array $row): string => (string) $row['migration'], $rows);
        $pending = [];
        foreach (glob($this->directory . '/*.sql') ?: [] as $file) {
            if (!in_array(basename($file), $done, true)) {
                $pending[] = basename($file);
            }
        }
        sort($pending);

        return [
            'applied' => array_map(static fn (array $row): array => ['name' => (string) $row['migration'], 'at' => (string) $row['applied_at']], array_slice($rows, 0, 10)),
            'pending' => $pending,
        ];
    }
}
