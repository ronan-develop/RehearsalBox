<?php

declare(strict_types=1);

namespace App\Tests\Operations\Backup;

use App\Backup\Restore\BackupCatalog;
use App\Metrics\Report\HealthStatus;
use App\Metrics\Report\Thresholds;
use App\Operations\Backup\Declarations;
use App\Operations\Backup\KeyFileProbe;
use App\Operations\Backup\ProtectionHealthBuilder;
use App\Operations\OpsPaths;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ProtectionHealthBuilderTest extends TestCase
{
    private const NOW = '2026-10-11 12:00:00';

    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/rb-OPSCANARY-' . bin2hex(random_bytes(4));
        foreach (['', '/shared', '/shared/storage', '/shared/storage/logs', '/backups'] as $dir) {
            mkdir($this->base . $dir, 0o700);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base . '/{shared/storage/logs,shared,backups}/*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) ? unlink($file) : null;
        }
        foreach (['/shared/storage/logs', '/shared/storage', '/shared', '/backups', ''] as $dir) {
            @rmdir($this->base . $dir);
        }
    }

    private function builder(?string $keyFile = null): ProtectionHealthBuilder
    {
        $clock = new MockClock(self::NOW);
        $paths = new OpsPaths($this->base);

        return new ProtectionHealthBuilder(
            new KeyFileProbe($keyFile ?? $this->base . '/absent.json'),
            new Declarations($paths->declarations(), $clock),
            $paths,
            new BackupCatalog($this->base . '/backups'),
            new Thresholds(),
            $clock,
            new \DateTimeZone('Europe/Paris'),
        );
    }

    private function touchAt(string $path, string $when): void
    {
        file_put_contents($path, 'x');
        touch($path, (new \DateTimeImmutable($when, new \DateTimeZone('UTC')))->getTimestamp());
    }

    public function testMissingLogsAreUnknownNotExceptions(): void
    {
        $cards = $this->builder()->cards();

        foreach (['task_backup', 'task_reminders', 'task_collect'] as $key) {
            self::assertSame(HealthStatus::Unknown, $cards[$key]->status, $key);
        }
    }

    public function testMissingKeyFileIsCritical(): void
    {
        self::assertSame(HealthStatus::Critical, $this->builder()->cards()['key_file']->status);
    }

    public function testValidKeyFileIsOkAndShowsOnlyACount(): void
    {
        $key = $this->base . '/shared/keys.json';
        file_put_contents($key, (string) json_encode(['current' => 'k1', 'allow_plaintext' => false, 'keys' => ['k1' => base64_encode(random_bytes(32))]]));
        chmod($key, 0o600);

        $card = $this->builder($key)->cards()['key_file'];

        self::assertSame(HealthStatus::Ok, $card->status);
        self::assertStringContainsString('1 clé(s)', $card->hint);
        self::assertStringNotContainsString('k1', $card->label . $card->value . $card->hint);
    }

    public function testNeverDeclaredWarns(): void
    {
        $cards = $this->builder()->cards();

        self::assertSame(HealthStatus::Warning, $cards['offsite']->status);
        self::assertSame(HealthStatus::Warning, $cards['key_saved']->status);
    }

    public function testOffsiteCopyAgesWithTheThresholds(): void
    {
        $declarations = $this->base . '/shared/ops-declarations.json';
        foreach ([[ '2026-10-10T12:00:00Z', HealthStatus::Ok ], ['2026-10-02T12:00:00Z', HealthStatus::Warning], ['2026-09-01T12:00:00Z', HealthStatus::Critical]] as [$date, $expected]) {
            file_put_contents($declarations, (string) json_encode(['offsiteAt' => $date]));

            self::assertSame($expected, $this->builder()->cards()['offsite']->status, $date);
        }
    }

    public function testRemindersLogAgeDrivesTheStatus(): void
    {
        $this->touchAt($this->base . '/shared/storage/logs/cron.log', '2026-10-11 11:30:00');
        self::assertSame(HealthStatus::Ok, $this->builder()->cards()['task_reminders']->status);

        $this->touchAt($this->base . '/shared/storage/logs/cron.log', '2026-10-11 08:00:00'); // 240 min
        self::assertSame(HealthStatus::Critical, $this->builder()->cards()['task_reminders']->status);
    }

    public function testBackupTaskThatRanWithoutProducingADumpIsCritical(): void
    {
        file_put_contents($this->base . '/backups/db-20261010013000.sql.gz', 'x');
        $this->touchAt($this->base . '/shared/backup.log', '2026-10-11 01:30:10');

        self::assertSame(HealthStatus::Critical, $this->builder()->cards()['task_backup']->status);
    }

    public function testBackupTaskWithAFreshDumpIsOk(): void
    {
        file_put_contents($this->base . '/backups/db-20261011013000.sql.gz', 'x');
        $this->touchAt($this->base . '/shared/backup.log', '2026-10-11 01:30:10');

        self::assertSame(HealthStatus::Ok, $this->builder()->cards()['task_backup']->status);
    }

    public function testNoCardEverContainsThePath(): void
    {
        foreach ($this->builder()->cards() as $card) {
            self::assertStringNotContainsString('OPSCANARY', $card->label . $card->value . $card->hint);
        }
    }
}
