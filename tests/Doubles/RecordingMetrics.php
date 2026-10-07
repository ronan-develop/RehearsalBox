<?php

declare(strict_types=1);

namespace App\Tests\Doubles;

use App\Metrics\MetricEventType;
use App\Metrics\Collection\MetricsRecorderInterface;

final class RecordingMetrics implements MetricsRecorderInterface
{
    /** @var list<array{string, string, ?int, string}> type, route, statut, adresse brute */
    public array $events = [];

    /** @var list<array{string, int}> route, statut */
    public array $requests = [];

    public int $flushed = 0;

    public function event(MetricEventType $type, string $route, ?int $status = null, string $ip = ''): void
    {
        $this->events[] = [$type->value, $route, $status, $ip];
    }

    public function request(string $route, int $status, int $durationMs, int $memoryPeakKb): void
    {
        $this->requests[] = [$route, $status];
    }

    public function flush(): void
    {
        ++$this->flushed;
    }

    /** @return list<string> les types d'évènements, dans l'ordre */
    public function eventTypes(): array
    {
        return array_column($this->events, 0);
    }
}
