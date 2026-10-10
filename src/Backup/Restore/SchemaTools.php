<?php

declare(strict_types=1);

namespace App\Backup\Restore;

/**
 * Inspecter et vider un schéma de la base (#241). L'utilisateur de l'application n'a pas le droit de supprimer la base elle-même :
 * « vider » = supprimer ses TABLES. Le nom du schéma est validé (identifiant simple) avant d'être placé dans une instruction, et
 * seules les tables de CE schéma sont touchées.
 */
final class SchemaTools
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @return list<string> tables (pas les vues) du schéma, par ordre alphabétique
     *
     * @throws \InvalidArgumentException
     */
    public function tables(string $schema): array
    {
        $this->assertIdentifier($schema);
        $statement = $this->pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
        $statement->execute([$schema]);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function isEmpty(string $schema): bool
    {
        return $this->tables($schema) === [];
    }

    /**
     * @return int nombre de tables supprimées
     *
     * @throws \InvalidArgumentException
     */
    public function dropAllTables(string $schema): int
    {
        $tables = $this->tables($schema);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                $this->pdo->exec(sprintf('DROP TABLE `%s`.`%s`', $schema, str_replace('`', '``', $table)));
            }
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        return count($tables);
    }

    private function assertIdentifier(string $schema): void
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $schema) !== 1) {
            throw new \InvalidArgumentException('Nom de base invalide.');
        }
    }
}
