<?php

declare(strict_types=1);

namespace App\Tests;

final class TestDatabase
{
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

    /** Repart d'une base vide avant chaque test qui en a besoin. */
    public static function reset(\PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
