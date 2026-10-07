<?php

declare(strict_types=1);

namespace App\Tests\Doubles;

use App\Metrics\HealthSnapshot;
use App\Metrics\MetricEventType;
use App\Metrics\Collection\MetricsRepositoryInterface;

final class InMemoryMetricsRepository implements MetricsRepositoryInterface
{
    /** @var list<array{type: string, route: string, status: ?int, ip: ?string, at: string}> */
    public array $events = [];

    /** @var list<array{hour: string, route: string, class: string, requests: int, total: int, max: int, memory: int}> */
    public array $hourly = [];

    /** @var list<HealthSnapshot> */
    public array $snapshots = [];

    /** @var list<array{string, string, string}> */
    public array $purges = [];

    public bool $failing = false;

    public function addEvent(MetricEventType $type, string $route, ?int $status, ?string $ipHash, \DateTimeImmutable $at): void
    {
        $this->failIfAsked();
        $this->events[] = ['type' => $type->value, 'route' => $route, 'status' => $status, 'ip' => $ipHash, 'at' => $at->format('Y-m-d H:i:s')];
    }

    public function addHourly(\DateTimeImmutable $hourStart, string $route, string $statusClass, int $requests, int $durationTotalMs, int $durationMaxMs, int $memoryPeakKb): void
    {
        $this->failIfAsked();
        $this->hourly[] = ['hour' => $hourStart->format('Y-m-d H:i:s'), 'route' => $route, 'class' => $statusClass, 'requests' => $requests, 'total' => $durationTotalMs, 'max' => $durationMaxMs, 'memory' => $memoryPeakKb];
    }

    public function saveSnapshot(HealthSnapshot $snapshot): void
    {
        $this->snapshots[] = $snapshot;
    }

    public function databaseSizeBytes(): ?int
    {
        return 4096;
    }

    public function purge(\DateTimeImmutable $eventsBefore, \DateTimeImmutable $hourlyBefore, \DateTimeImmutable $snapshotsBefore): int
    {
        $this->purges[] = [$eventsBefore->format('Y-m-d'), $hourlyBefore->format('Y-m-d'), $snapshotsBefore->format('Y-m-d')];

        return 3;
    }

    private function failIfAsked(): void
    {
        if ($this->failing) {
            throw new \RuntimeException('base indisponible alice@rehearsalbox.test');
        }
    }
}
