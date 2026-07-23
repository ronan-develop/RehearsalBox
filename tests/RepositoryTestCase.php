<?php

declare(strict_types=1);

namespace App\Tests;

use App\Migration\Migrator;
use PHPUnit\Framework\TestCase;

abstract class RepositoryTestCase extends TestCase
{
    protected \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        TestDatabase::reset($this->pdo);

        (new Migrator($this->pdo, __DIR__ . '/../database/migrations'))->run();
    }
}
