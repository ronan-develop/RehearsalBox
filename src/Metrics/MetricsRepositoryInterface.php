<?php

declare(strict_types=1);

namespace App\Metrics;

interface MetricsRepositoryInterface
{
    public function addEvent(MetricEventType $type, string $route, ?int $status, ?string $ipHash, \DateTimeImmutable $at): void;

    /** Ajoute à l'agrégat de l'heure (UPSERT) : les compteurs s'additionnent, durée et mémoire gardent leur maximum. */
    public function addHourly(\DateTimeImmutable $hourStart, string $route, string $statusClass, int $requests, int $durationTotalMs, int $durationMaxMs, int $memoryPeakKb): void;

    public function saveSnapshot(HealthSnapshot $snapshot): void;

    /** Taille de la base en octets, null si elle n'est pas lisible. */
    public function databaseSizeBytes(): ?int;

    /** Supprime les évènements, agrégats et instantanés plus anciens que les dates données ; renvoie le nombre de lignes supprimées. */
    public function purge(\DateTimeImmutable $eventsBefore, \DateTimeImmutable $hourlyBefore, \DateTimeImmutable $snapshotsBefore): int;
}
