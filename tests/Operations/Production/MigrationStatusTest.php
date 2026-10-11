<?php

declare(strict_types=1);

namespace App\Tests\Operations\Production;

use App\Operations\Production\MigrationStatus;
use App\Tests\Database\TestDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class MigrationStatusTest extends TestCase
{
    private string $dir;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        $this->dir = sys_get_temp_dir() . '/rb-migr-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700);
        $this->pdo->exec('DROP TABLE IF EXISTS migrations_log_backup_test');
        $this->pdo->exec('RENAME TABLE migrations_log TO migrations_log_backup_test');
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS migrations_log');
        $this->pdo->exec('RENAME TABLE migrations_log_backup_test TO migrations_log');
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function createLog(): void
    {
        $this->pdo->exec('CREATE TABLE migrations_log (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(190) NOT NULL, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq (migration)) ENGINE=InnoDB');
    }

    #[Test]
    public function testMissingLogTableIsUnknownNotAnException(): void
    {
        self::assertNull((new MigrationStatus($this->pdo, $this->dir))->status());
        self::assertNull((new MigrationStatus(null, $this->dir))->status());
    }

    #[Test]
    public function testEmptyLogMeansEverythingInTheFolderIsPending(): void
    {
        $this->createLog();
        touch($this->dir . '/001_a.sql');
        touch($this->dir . '/002_b.sql');

        $status = (new MigrationStatus($this->pdo, $this->dir))->status();

        self::assertSame([], $status['applied']);
        self::assertSame(['001_a.sql', '002_b.sql'], $status['pending']);
    }

    #[Test]
    public function testAppliedAreTheLastTenNewestFirstAndPendingTheRest(): void
    {
        $this->createLog();
        $insert = $this->pdo->prepare('INSERT INTO migrations_log (migration) VALUES (?)');
        for ($i = 1; $i <= 12; ++$i) {
            $name = sprintf('%03d_m.sql', $i);
            touch($this->dir . '/' . $name);
            $insert->execute([$name]);
        }
        touch($this->dir . '/013_nouvelle.sql');
        touch($this->dir . '/notes.txt');

        $status = (new MigrationStatus($this->pdo, $this->dir))->status();

        self::assertCount(10, $status['applied']);
        self::assertSame('012_m.sql', $status['applied'][0]['name']);
        self::assertSame(['013_nouvelle.sql'], $status['pending']);
    }
}
