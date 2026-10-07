<?php

declare(strict_types=1);

namespace App\Metrics\Report;

use App\Metrics\HealthSnapshot;
use App\Metrics\MetricEventType;
use App\Metrics\Report\Security\SecurityEvent;

final class MysqlMetricsReader implements MetricsReaderInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function requestsByHour(\DateTimeImmutable $since): array
    {
        $statement = $this->pdo->prepare(
            "SELECT hour_start, SUM(requests) AS requests,
                    SUM(CASE WHEN status_class = '5xx' THEN requests ELSE 0 END) AS errors,
                    SUM(duration_total_ms) AS duration_ms
             FROM metric_hourly WHERE hour_start >= :since GROUP BY hour_start ORDER BY hour_start"
        );
        $statement->execute(['since' => $since->format(self::DATE_FORMAT)]);

        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[(string) $row['hour_start']] = ['requests' => (int) $row['requests'], 'errors' => (int) $row['errors'], 'durationMs' => (int) $row['duration_ms']];
        }

        return $rows;
    }

    public function eventsByHour(\DateTimeImmutable $since, array $types): array
    {
        if ($types === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($types), '?'));
        $statement = $this->pdo->prepare(
            "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS hour_start, type, COUNT(*) AS n
             FROM metric_events WHERE created_at >= ? AND type IN ({$marks}) GROUP BY hour_start, type"
        );
        $statement->execute([$since->format(self::DATE_FORMAT), ...array_map(static fn (MetricEventType $t): string => $t->value, $types)]);

        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[(string) $row['hour_start']][(string) $row['type']] = (int) $row['n'];
        }

        return $rows;
    }

    public function securityEvents(\DateTimeImmutable $since, int $limit = 5000): array
    {
        $types = [MetricEventType::LoginFailed, MetricEventType::AccessDenied, MetricEventType::CsrfFailed, MetricEventType::RateLimited, MetricEventType::NotFound];
        $marks = implode(',', array_fill(0, count($types), '?'));
        $statement = $this->pdo->prepare(
            "SELECT type, route, ip_hash, created_at FROM metric_events WHERE created_at >= ? AND type IN ({$marks}) ORDER BY created_at DESC, id DESC LIMIT " . max(1, $limit)
        );
        $statement->execute([$since->format(self::DATE_FORMAT), ...array_map(static fn (MetricEventType $t): string => $t->value, $types)]);

        $utc = new \DateTimeZone('UTC');

        return array_map(
            static fn (array $row): SecurityEvent => new SecurityEvent(
                MetricEventType::from((string) $row['type']),
                (string) $row['route'],
                $row['ip_hash'] === null ? null : (string) $row['ip_hash'],
                new \DateTimeImmutable((string) $row['created_at'], $utc),
            ),
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    public function latestSnapshot(): ?HealthSnapshot
    {
        $row = $this->pdo->query('SELECT * FROM health_snapshots ORDER BY taken_at DESC, id DESC LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $utc = new \DateTimeZone('UTC');

        return new HealthSnapshot(
            takenAt: new \DateTimeImmutable((string) $row['taken_at'], $utc),
            diskFreeBytes: $row['disk_free_bytes'] === null ? null : (int) $row['disk_free_bytes'],
            dbSizeBytes: $row['db_size_bytes'] === null ? null : (int) $row['db_size_bytes'],
            backupAgeHours: $row['backup_age_hours'] === null ? null : (int) $row['backup_age_hours'],
            lastCronAt: $row['last_cron_at'] === null ? null : new \DateTimeImmutable((string) $row['last_cron_at'], $utc),
            releaseMarker: $row['release_marker'] === null ? null : (string) $row['release_marker'],
            phpVersion: (string) $row['php_version'],
            load1m: $row['load_1m'] === null ? null : (float) $row['load_1m'],
        );
    }
}
