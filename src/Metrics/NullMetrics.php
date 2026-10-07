<?php

declare(strict_types=1);

namespace App\Metrics;

/** Collecte désactivée (valeur par défaut des classes qui signalent, et des tests). */
final class NullMetrics implements MetricsRecorderInterface
{
    public function event(MetricEventType $type, string $route, ?int $status = null, string $ip = ''): void
    {
    }

    public function request(string $route, int $status, int $durationMs, int $memoryPeakKb): void
    {
    }

    public function flush(): void
    {
    }
}
