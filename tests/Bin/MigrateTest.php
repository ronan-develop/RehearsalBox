<?php

declare(strict_types=1);

namespace App\Tests\Bin;

use App\Migration\Migrator;
use App\Tests\TestDatabase;
use PHPUnit\Framework\TestCase;

final class MigrateTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        TestDatabase::reset($this->pdo);
    }

    public function testRunAppliesEachMigrationFileOnce(): void
    {
        $migrator = new Migrator($this->pdo, __DIR__ . '/../../database/migrations');

        $applied = $migrator->run();

        self::assertNotEmpty($applied, 'au moins une migration doit être appliquée sur une base vide');

        $logged = $this->pdo
            ->query('SELECT migration FROM migrations_log ORDER BY migration')
            ->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame($applied, $logged);

        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('users', $tables);
        self::assertContains('groups', $tables);
        self::assertContains('group_user', $tables);
        self::assertContains('recurring_slots', $tables);
        self::assertContains('slot_exceptions', $tables);
    }

    public function testRunTwiceDoesNotReapplyAlreadyLoggedMigrations(): void
    {
        $migrator = new Migrator($this->pdo, __DIR__ . '/../../database/migrations');

        $firstRun = $migrator->run();
        self::assertNotEmpty($firstRun);

        $secondRun = $migrator->run();

        self::assertSame([], $secondRun, 'aucune migration ne doit être rejouée si déjà loguée');
    }
}
