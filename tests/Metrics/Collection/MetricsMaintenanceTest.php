<?php

declare(strict_types=1);

namespace App\Tests\Metrics\Collection;

use App\Metrics\Collection\HealthProbe;
use App\Metrics\Collection\MetricsMaintenance;
use App\Tests\Doubles\InMemoryMetricsRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class MetricsMaintenanceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rb-health-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/backups', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($this->dir);
    }

    private function probe(InMemoryMetricsRepository $repository, MockClock $clock, ?string $backupDir): HealthProbe
    {
        return new HealthProbe($repository, $clock, $this->dir, $backupDir, $this->dir . '/cron.log', $this->dir . '/RELEASE');
    }

    #[Test]
    public function testASnapshotReadsDiskDatabaseBackupCronReleaseAndPhp(): void
    {
        $clock = new MockClock('2026-10-07 12:00:00 UTC');
        touch($this->dir . '/backups/dump.sql.gz', $clock->now()->getTimestamp() - 5 * 3600 - 120);
        touch($this->dir . '/cron.log', $clock->now()->getTimestamp() - 600);
        file_put_contents($this->dir . '/RELEASE', "20261007120000-abc1234\n");

        $snapshot = $this->probe(new InMemoryMetricsRepository(), $clock, $this->dir . '/backups')->snapshot();

        self::assertGreaterThan(0, $snapshot->diskFreeBytes);
        self::assertSame(4096, $snapshot->dbSizeBytes);
        self::assertSame(5, $snapshot->backupAgeHours);
        self::assertSame('2026-10-07 11:50:00', $snapshot->lastCronAt?->format('Y-m-d H:i:s'));
        self::assertSame('20261007120000-abc1234', $snapshot->releaseMarker);
        self::assertSame(PHP_VERSION, $snapshot->phpVersion);
    }

    #[Test]
    public function testWhatIsUnavailableIsNullInsteadOfAnError(): void
    {
        $snapshot = $this->probe(new InMemoryMetricsRepository(), new MockClock(), null)->snapshot();

        self::assertNull($snapshot->backupAgeHours);
        self::assertNull($snapshot->lastCronAt);
        self::assertNull($snapshot->releaseMarker);
    }

    #[Test]
    public function testAnEmptyBackupFolderHasNoAge(): void
    {
        self::assertNull($this->probe(new InMemoryMetricsRepository(), new MockClock(), $this->dir . '/backups')->snapshot()->backupAgeHours);
    }

    #[Test]
    public function testTheHourlyTaskSavesASnapshotAndPurgesWithTheDecidedRetention(): void
    {
        $clock = new MockClock('2026-10-07 12:00:00 UTC');
        $repository = new InMemoryMetricsRepository();

        $purged = (new MetricsMaintenance($repository, $this->probe($repository, $clock, null), $clock))->run();

        self::assertCount(1, $repository->snapshots);
        self::assertSame(3, $purged);
        self::assertSame([['2026-09-07', '2026-07-09', '2026-07-09']], $repository->purges, 'événements 30 jours, agrégats et instantanés 90 jours');
    }
}
