<?php

declare(strict_types=1);

namespace App\Tests\Doubles;

use App\Metrics\HealthSnapshot;
use App\Metrics\Report\Security\SecurityEvent;
use App\Metrics\Report\MetricsReaderInterface;

final class FakeMetricsReader implements MetricsReaderInterface
{
    /** @var array<string, array{requests: int, errors: int, durationMs: int}> */
    public array $requests = [];

    /** @var array<string, array{requests: int, errors: int, durationMs: int, memoryKb: int, buckets: list<int>}> */
    public array $load = [];

    /** @var list<array{route: string, requests: int, durationMs: int, maxMs: int}> */
    public array $routes = [];

    /** @var array<string, array<string, int>> */
    public array $events = [];

    /** @var list<SecurityEvent> */
    public array $securityEvents = [];

    public ?HealthSnapshot $snapshot = null;

    public ?\DateTimeImmutable $requestedSince = null;

    public function requestsByHour(\DateTimeImmutable $since): array
    {
        $this->requestedSince = $since;

        return $this->requests;
    }

    public function loadByHour(\DateTimeImmutable $since): array
    {
        $this->requestedSince = $since;

        return $this->load;
    }

    public function routeTotals(\DateTimeImmutable $since, int $limit = 10): array
    {
        return $this->routes;
    }

    public function eventsByHour(\DateTimeImmutable $since, array $types): array
    {
        return $this->events;
    }

    public function securityEvents(\DateTimeImmutable $since, int $limit = 5000): array
    {
        return $this->securityEvents;
    }

    public function latestSnapshot(): ?HealthSnapshot
    {
        return $this->snapshot;
    }
}
