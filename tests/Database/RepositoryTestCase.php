<?php

declare(strict_types=1);

namespace App\Tests\Database;

use PHPUnit\Framework\TestCase;

abstract class RepositoryTestCase extends TestCase
{
    protected \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        // Schéma construit une seule fois par exécution ; entre deux tests, seules les tables touchées sont vidées (#207).
        TestDatabase::fresh($this->pdo, __DIR__ . '/../../database/migrations');
    }
}
