<?php

declare(strict_types=1);

namespace App\Metrics;

use Psr\Clock\ClockInterface;

/** La tâche horaire de collecte (#195) : un instantané de santé, puis la purge selon la rétention décidée. */
final class MetricsMaintenance
{
    public const EVENTS_RETENTION = '-30 days';
    public const AGGREGATES_RETENTION = '-90 days';

    public function __construct(
        private readonly MetricsRepositoryInterface $repository,
        private readonly HealthProbe $probe,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return int nombre de lignes purgées */
    public function run(): int
    {
        $this->repository->saveSnapshot($this->probe->snapshot());

        $now = $this->clock->now();

        return $this->repository->purge($now->modify(self::EVENTS_RETENTION), $now->modify(self::AGGREGATES_RETENTION), $now->modify(self::AGGREGATES_RETENTION));
    }
}
