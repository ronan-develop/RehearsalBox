<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Migration\Migrator;

final class TestDatabase
{
    /** Vrai quand le schéma complet est en place pour cette exécution de PHPUnit (il ne change jamais en cours de route). */
    private static bool $schemaReady = false;

    public static function connection(): \PDO
    {
        $host = getenv('DB_TEST_HOST') ?: '127.0.0.1';
        $port = getenv('DB_TEST_PORT') ?: '3307';
        $name = getenv('DB_TEST_NAME') ?: 'rehearsalbox_test';
        $user = getenv('DB_TEST_USER') ?: 'root';
        $pass = getenv('DB_TEST_PASSWORD') ?: 'root';

        $pdo = new \PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        return $pdo;
    }

    /**
     * Prépare la base d'un test : le schéma est construit UNE fois par exécution (destruction + migrations), puis, entre deux
     * tests, seules les tables touchées sont vidées (TRUNCATE : compteurs d'identifiants remis à zéro). Une table est
     * touchée si elle contient une ligne ou si son compteur a avancé (le test a pu la vider lui-même).
     */
    public static function fresh(\PDO $pdo, string $migrationsDirectory): void
    {
        if (!self::$schemaReady) {
            self::reset($pdo);
            (new Migrator($pdo, $migrationsDirectory))->run();
            self::$schemaReady = true;

            return;
        }

        $counters = $pdo->query(
            "SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($counters as $table => $autoIncrement) {
            if ($table === 'migrations_log') {
                continue; // le journal décrit le schéma, pas les données du test
            }
            $touched = ($autoIncrement !== null && (int) $autoIncrement > 1)
                || (int) $pdo->query("SELECT EXISTS (SELECT 1 FROM `{$table}`)")->fetchColumn() === 1;
            if ($touched) {
                $pdo->exec("TRUNCATE TABLE `{$table}`");
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** Détruit toutes les tables et invalide l'état « schéma prêt » : la prochaine préparation reconstruira tout. */
    public static function reset(\PDO $pdo): void
    {
        self::$schemaReady = false;

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
