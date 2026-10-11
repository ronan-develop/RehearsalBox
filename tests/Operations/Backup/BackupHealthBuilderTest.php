<?php

declare(strict_types=1);

namespace App\Tests\Operations\Backup;

use App\Backup\Restore\BackupCatalog;
use App\Backup\Restore\RestoreStatus;
use App\Backup\Restore\SchemaTools;
use App\Metrics\Report\HealthStatus;
use App\Metrics\Report\Thresholds;
use App\Operations\Backup\BackupHealthBuilder;
use App\Tests\Database\TestDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class BackupHealthBuilderTest extends TestCase
{
    private const NOW = '2026-10-11 12:00:00';
    private const SCRATCH = 'rehearsalbox_ops_scratch_test';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rb-OPSCANARY-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        TestDatabase::connection()->exec('DROP DATABASE IF EXISTS `' . self::SCRATCH . '`');
    }

    private function dump(string $name, int $bytes = 100): void
    {
        file_put_contents($this->dir . '/' . $name, str_repeat('x', $bytes));
    }

    private function builder(?string $dir = null, bool $scratch = false): BackupHealthBuilder
    {
        $clock = new MockClock(self::NOW);

        return new BackupHealthBuilder(
            new BackupCatalog($dir ?? $this->dir),
            new RestoreStatus($this->dir, $clock),
            $scratch ? new SchemaTools(TestDatabase::connection()) : null,
            $scratch ? self::SCRATCH : null,
            new Thresholds(),
            $clock,
            new \DateTimeZone('Europe/Paris'),
        );
    }

    #[Test]
    public function testMissingDirectoryIsUnknownNotAnException(): void
    {
        $cards = $this->builder($this->dir . '/absent')->cards();

        self::assertSame(HealthStatus::Unknown, $cards['daily_dump']->status);
        self::assertSame(HealthStatus::Unknown, $cards['retention']->status);
        self::assertSame([], $this->builder($this->dir . '/absent')->recent());
    }

    #[Test]
    public function testEmptyDirectoryIsCritical(): void
    {
        $cards = $this->builder()->cards();

        self::assertSame(HealthStatus::Critical, $cards['daily_dump']->status);
        self::assertSame(HealthStatus::Critical, $cards['retention']->status);
    }

    #[Test]
    public function testRecentPreDeployDumpNeverHidesAMissingDailyDump(): void
    {
        $this->dump('pre-20261011115900-20261011115800-abc1234.sql.gz');

        $cards = $this->builder()->cards();

        self::assertSame(HealthStatus::Critical, $cards['daily_dump']->status);
        self::assertStringContainsString('il y a 0 min', $cards['predeploy_dump']->value);
    }

    #[Test]
    public function testDailyDumpAgeDrivesTheStatus(): void
    {
        $this->dump('db-20261011100000.sql.gz'); // 2 h
        self::assertSame(HealthStatus::Ok, $this->builder()->cards()['daily_dump']->status);

        unlink($this->dir . '/db-20261011100000.sql.gz');
        $this->dump('db-20261010040000.sql.gz'); // 32 h => orange
        self::assertSame(HealthStatus::Warning, $this->builder()->cards()['daily_dump']->status);

        unlink($this->dir . '/db-20261010040000.sql.gz');
        $this->dump('db-20261008000000.sql.gz'); // 84 h => rouge
        self::assertSame(HealthStatus::Critical, $this->builder()->cards()['daily_dump']->status);
    }

    #[Test]
    public function testClockBehindTheDumpGivesAgeZeroNotNegative(): void
    {
        $this->dump('db-20261011130000.sql.gz');

        $card = $this->builder()->cards()['daily_dump'];

        self::assertSame(HealthStatus::Ok, $card->status);
        self::assertStringContainsString('0 min', $card->value);
    }

    #[Test]
    public function testRetentionBeyondTheLimitWarnsBecauseRotationIsBlocked(): void
    {
        for ($day = 1; $day <= 15; ++$day) {
            $this->dump(sprintf('db-202610%02d030000.sql.gz', $day));
        }

        self::assertSame(HealthStatus::Warning, $this->builder()->cards()['retention']->status);
    }

    #[Test]
    public function testSizeAndTrendAreComputedFromTheCatalogOnly(): void
    {
        $this->dump('db-20261009030000.sql.gz', 2048);
        $this->dump('db-20261010030000.sql.gz', 4096);

        $builder = $this->builder();

        self::assertSame('6,0 Ko', $builder->cards()['backups_size']->value);
        self::assertSame(['09/10', '10/10'], $builder->dailyTrend()['labels']);
        self::assertSame([2.0, 4.0], $builder->dailyTrend()['values']);
        self::assertCount(2, $builder->recent(10));
    }

    #[Test]
    public function testForgedFileNamesNeverEnterTheCatalog(): void
    {
        $this->dump('db-20261011100000<script>.sql.gz');
        $this->dump('notes.txt');

        self::assertSame([], $this->builder()->recent());
    }

    #[Test]
    public function testFailedRestorationIsAWarningWithoutItsMessage(): void
    {
        $status = new RestoreStatus($this->dir, new MockClock(self::NOW));
        $status->begin('db-20261011100000.sql.gz');
        $status->fail('Erreur interne avec un détail SECRETDETAIL');

        $card = $this->builder()->cards()['last_restore'];

        self::assertSame(HealthStatus::Warning, $card->status);
        self::assertStringNotContainsString('SECRETDETAIL', $card->value . $card->hint);
    }

    #[Test]
    public function testNoRestorationYet(): void
    {
        $card = $this->builder()->cards()['last_restore'];

        self::assertSame('jamais', $card->value);
        self::assertNull($card->status);
    }

    #[Test]
    public function testScratchSchemaIsOkWhenEmptyAndWarnsWhenNot(): void
    {
        $pdo = TestDatabase::connection();
        $pdo->exec('DROP DATABASE IF EXISTS `' . self::SCRATCH . '`');
        $pdo->exec('CREATE DATABASE `' . self::SCRATCH . '`');
        self::assertSame(HealthStatus::Ok, $this->builder(null, true)->cards()['scratch_empty']->status);

        $pdo->exec('CREATE TABLE `' . self::SCRATCH . '`.t (id INT)');
        self::assertSame(HealthStatus::Warning, $this->builder(null, true)->cards()['scratch_empty']->status);
    }

    #[Test]
    public function testNoScratchSchemaConfiguredIsNeutral(): void
    {
        $card = $this->builder()->cards()['scratch_empty'];

        self::assertNull($card->status);
        self::assertSame('non configurée', $card->value);
    }

    #[Test]
    public function testNoCardEverContainsThePath(): void
    {
        $this->dump('db-20261011100000.sql.gz');
        $cards = $this->builder()->cards();

        foreach ($cards as $card) {
            self::assertStringNotContainsString('OPSCANARY', $card->label . $card->value . $card->hint);
        }
    }
}
