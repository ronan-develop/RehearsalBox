<?php

declare(strict_types=1);

namespace App\Migration;

final class Migrator
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $migrationsDirectory,
    ) {
    }

    /** @return list<string> noms des fichiers de migration appliqués lors de cet appel */
    public function run(): array
    {
        $this->ensureMigrationsLogTableExists();

        $alreadyApplied = $this->pdo
            ->query('SELECT migration FROM migrations_log')
            ->fetchAll(\PDO::FETCH_COLUMN);

        $files = glob($this->migrationsDirectory . '/*.sql') ?: [];
        sort($files);

        $applied = [];
        foreach ($files as $file) {
            $migration = basename($file);
            if (in_array($migration, $alreadyApplied, true)) {
                continue;
            }

            // Le DDL (CREATE TABLE...) déclenche un commit implicite en
            // MySQL/MariaDB : pas de transaction possible autour de exec(),
            // seul l'enregistrement dans migrations_log en bénéficie.
            $this->pdo->exec((string) file_get_contents($file));

            $statement = $this->pdo->prepare(
                'INSERT INTO migrations_log (migration) VALUES (:migration)'
            );
            $statement->execute(['migration' => $migration]);

            $applied[] = $migration;
        }

        return $applied;
    }

    private function ensureMigrationsLogTableExists(): void
    {
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS migrations_log (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration    VARCHAR(190) NOT NULL,
                applied_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_migrations_log_migration (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
