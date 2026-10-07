<?php

declare(strict_types=1);

namespace App\Metrics\Report;

use App\Metrics\HealthSnapshot;
use App\Metrics\MetricEventType;
use App\Metrics\Report\Security\SecurityEvent;

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
     * Charge par heure (UTC), toutes routes confondues : requêtes, 5xx, durée cumulée, pic de mémoire et répartition des durées.
     *
     * @return array<string, array{requests: int, errors: int, durationMs: int, memoryKb: int, buckets: list<int>}> clé « Y-m-d H:00:00 »
     */
    public function loadByHour(\DateTimeImmutable $since): array;

    /**
     * Les routes les plus sollicitées depuis la date donnée (motifs sans identifiant).
     *
     * @return list<array{route: string, requests: int, durationMs: int, maxMs: int}>
     */
    public function routeTotals(\DateTimeImmutable $since, int $limit = 10): array;

    /**
     * Évènements par heure (UTC) et par type.
     *
     * @param list<MetricEventType> $types
     *
     * @return array<string, array<string, int>> heure => type => nombre
     */
    public function eventsByHour(\DateTimeImmutable $since, array $types): array;

    /**
     * Évènements de sécurité (échecs de connexion, refus, CSRF, limites de débit, pages introuvables) depuis la date donnée, les
     * plus récents d'abord, plafonnés pour borner le coût de la détection.
     *
     * @return list<SecurityEvent>
     */
    public function securityEvents(\DateTimeImmutable $since, int $limit = 5000): array;

    public function latestSnapshot(): ?HealthSnapshot;
}
