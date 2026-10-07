<?php

declare(strict_types=1);

namespace App\Metrics\Collection;

use App\Metrics\MetricEventType;

/**
 * Ce que le site signale pour les rapports (#195). Les écritures sont mises de côté en mémoire et envoyées par `flush()`, une
 * fois la réponse livrée : aucune écriture dans le chemin critique d'une requête. La collecte ne fait JAMAIS échouer le site.
 */
interface MetricsRecorderInterface
{
    /** Un évènement ponctuel ; $ip est l'adresse brute, jamais conservée en clair (empreinte HMAC tronquée). */
    public function event(MetricEventType $type, string $route, ?int $status = null, string $ip = ''): void;

    /** Une requête servie, comptée dans l'agrégat horaire de sa route (motif, sans identifiant) et de sa classe de statut. */
    public function request(string $route, int $status, int $durationMs, int $memoryPeakKb): void;

    /** Écrit ce qui a été mis de côté. */
    public function flush(): void;
}
