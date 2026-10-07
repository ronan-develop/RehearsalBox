<?php

declare(strict_types=1);

namespace App\Metrics\Collection;

use App\Metrics\HealthSnapshot;
use App\Metrics\MetricEventType;

final class MysqlMetricsRepository implements MetricsRepositoryInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function addEvent(MetricEventType $type, string $route, ?int $status, ?string $ipHash, \DateTimeImmutable $at): void
    {
        $this->pdo->prepare('INSERT INTO metric_events (type, route, status, ip_hash, created_at) VALUES (:type, :route, :status, :ip, :at)')
            ->execute(['type' => $type->value, 'route' => $route, 'status' => $status, 'ip' => $ipHash, 'at' => $at->format(self::DATE_FORMAT)]);
    }

    public function addHourly(\DateTimeImmutable $hourStart, string $route, string $statusClass, int $requests, int $durationTotalMs, int $durationMaxMs, int $memoryPeakKb): void
    {
        $this->pdo->prepare(
            'INSERT INTO metric_hourly (hour_start, route, status_class, requests, duration_total_ms, duration_max_ms, memory_peak_kb)
             VALUES (:hour, :route, :class, :requests, :total, :max, :memory)
             ON DUPLICATE KEY UPDATE
                requests = requests + VALUES(requests),
                duration_total_ms = duration_total_ms + VALUES(duration_total_ms),
                duration_max_ms = GREATEST(duration_max_ms, VALUES(duration_max_ms)),
                memory_peak_kb = GREATEST(memory_peak_kb, VALUES(memory_peak_kb))'
        )->execute([
            'hour' => $hourStart->format(self::DATE_FORMAT),
            'route' => $route,
            'class' => $statusClass,
            'requests' => $requests,
            'total' => $durationTotalMs,
            'max' => $durationMaxMs,
            'memory' => $memoryPeakKb,
        ]);
    }

    public function saveSnapshot(HealthSnapshot $snapshot): void
    {
        $this->pdo->prepare(
            'INSERT INTO health_snapshots (taken_at, disk_free_bytes, db_size_bytes, backup_age_hours, last_cron_at, release_marker, php_version, load_1m)
             VALUES (:taken, :disk, :db, :backup, :cron, :release, :php, :load)'
        )->execute([
            'taken' => $snapshot->takenAt->format(self::DATE_FORMAT),
            'disk' => $snapshot->diskFreeBytes,
            'db' => $snapshot->dbSizeBytes,
            'backup' => $snapshot->backupAgeHours,
            'cron' => $snapshot->lastCronAt?->format(self::DATE_FORMAT),
            'release' => $snapshot->releaseMarker,
            'php' => $snapshot->phpVersion,
            'load' => $snapshot->load1m,
        ]);
    }

    public function databaseSizeBytes(): ?int
    {
        $size = $this->pdo->query(
            'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()'
        )->fetchColumn();

        return $size === false || $size === null ? null : (int) $size;
    }

    public function purge(\DateTimeImmutable $eventsBefore, \DateTimeImmutable $hourlyBefore, \DateTimeImmutable $snapshotsBefore): int
    {
        $deleted = 0;
        foreach ([
            ['metric_events', 'created_at', $eventsBefore],
            ['metric_hourly', 'hour_start', $hourlyBefore],
            ['health_snapshots', 'taken_at', $snapshotsBefore],
        ] as [$table, $column, $before]) {
            $statement = $this->pdo->prepare("DELETE FROM {$table} WHERE {$column} < :before");
            $statement->execute(['before' => $before->format(self::DATE_FORMAT)]);
            $deleted += $statement->rowCount();
        }

        return $deleted;
    }
}
