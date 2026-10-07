<?php

declare(strict_types=1);

namespace App\Metrics\Report;

use App\Metrics\HealthSnapshot;
use App\Metrics\MetricEventType;

/** Lecture des mesures pour les rapports (séparée de l'écriture : la collecte n'a jamais besoin de relire). */
interface MetricsReaderInterface
{
    /**
     * Requêtes par heure (UTC), toutes routes confondues.
     *
     * @return array<string, array{requests: int, errors: int, durationMs: int}> clé « Y-m-d H:00:00 »
     */
    public function requestsByHour(\DateTimeImmutable $since): array;

    /**
     * Évènements par heure (UTC) et par type.
     *
     * @param list<MetricEventType> $types
     *
     * @return array<string, array<string, int>> heure => type => nombre
     */
    public function eventsByHour(\DateTimeImmutable $since, array $types): array;

    public function latestSnapshot(): ?HealthSnapshot;
}
