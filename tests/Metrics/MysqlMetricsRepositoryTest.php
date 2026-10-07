<?php

declare(strict_types=1);

namespace App\Tests\Metrics;

use App\Metrics\HealthSnapshot;
use App\Metrics\MetricEventType;
use App\Metrics\MysqlMetricsRepository;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class MysqlMetricsRepositoryTest extends RepositoryTestCase
{
    private function at(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    #[Test]
    public function testHourlyCountersAddUpAndKeepTheMaximums(): void
    {
        $repository = new MysqlMetricsRepository($this->pdo);
        $repository->addHourly($this->at('2026-10-07 10:00:00'), '/login', '2xx', 3, 60, 30, 2048);
        $repository->addHourly($this->at('2026-10-07 10:00:00'), '/login', '2xx', 2, 100, 80, 1024);
        $repository->addHourly($this->at('2026-10-07 10:00:00'), '/login', '5xx', 1, 5, 5, 512);

        $rows = $this->pdo->query('SELECT status_class, requests, duration_total_ms, duration_max_ms, memory_peak_kb FROM metric_hourly ORDER BY status_class')->fetchAll(\PDO::FETCH_ASSOC);

        self::assertSame('5', (string) $rows[0]['requests']);
        self::assertSame('160', (string) $rows[0]['duration_total_ms']);
        self::assertSame('80', (string) $rows[0]['duration_max_ms']);
        self::assertSame('2048', (string) $rows[0]['memory_peak_kb']);
        self::assertSame('5xx', $rows[1]['status_class']);
    }

    #[Test]
    public function testAnEventIsStoredWithoutAnyPersonalColumn(): void
    {
        $repository = new MysqlMetricsRepository($this->pdo);
        $repository->addEvent(MetricEventType::LoginFailed, '/api/auth/login', 401, 'abcdef0123456789', $this->at('2026-10-07 10:00:00'));

        $row = $this->pdo->query('SELECT * FROM metric_events')->fetch(\PDO::FETCH_ASSOC);

        self::assertSame('login_failed', $row['type']);
        self::assertSame('abcdef0123456789', $row['ip_hash']);
        self::assertSame(['id', 'type', 'route', 'status', 'ip_hash', 'created_at'], array_keys($row));
    }

    #[Test]
    public function testASnapshotIsStoredAndTheDatabaseSizeIsReadable(): void
    {
        $repository = new MysqlMetricsRepository($this->pdo);
        $repository->saveSnapshot(new HealthSnapshot($this->at('2026-10-07 10:00:00'), 1000, 2000, 5, null, '2026-abc', '8.4.0', 0.5));

        self::assertSame('2026-abc', $this->pdo->query('SELECT release_marker FROM health_snapshots')->fetchColumn());
        self::assertGreaterThan(0, $repository->databaseSizeBytes());
    }

    #[Test]
    public function testPurgeRemovesOnlyWhatIsOlderThanEachDate(): void
    {
        $repository = new MysqlMetricsRepository($this->pdo);
        $repository->addEvent(MetricEventType::NotFound, '/old', 404, null, $this->at('2026-09-01 10:00:00'));
        $repository->addEvent(MetricEventType::NotFound, '/new', 404, null, $this->at('2026-10-06 10:00:00'));
        $repository->addHourly($this->at('2026-06-01 10:00:00'), '/a', '2xx', 1, 1, 1, 1);
        $repository->addHourly($this->at('2026-10-06 10:00:00'), '/a', '2xx', 1, 1, 1, 1);
        $repository->saveSnapshot(new HealthSnapshot($this->at('2026-06-01 10:00:00'), null, null, null, null, null, '8.4', null));

        $deleted = $repository->purge($this->at('2026-09-07 00:00:00'), $this->at('2026-07-09 00:00:00'), $this->at('2026-07-09 00:00:00'));

        self::assertSame(3, $deleted);
        self::assertSame(['/new'], $this->pdo->query('SELECT route FROM metric_events')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM metric_hourly')->fetchColumn());
    }
}
