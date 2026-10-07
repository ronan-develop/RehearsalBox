<?php

declare(strict_types=1);

namespace App\Metrics\Collection;

use App\Metrics\MetricEventType;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Met de côté les mesures de la requête et les écrit en un seul passage (`flush()`), une fois la réponse livrée. Les requêtes
 * sont agrégées EN MÉMOIRE par (heure, route, classe de statut) avant d'écrire : une seule ligne UPSERT par clé, quel que soit
 * le nombre de requêtes (le polling de la messagerie n'ajoute jamais une ligne par requête). Toute panne est journalisée et
 * absorbée : les mesures ne doivent jamais faire échouer le site.
 */
final class MetricsRecorder implements MetricsRecorderInterface
{
    private const ROUTE_MAX_LENGTH = 120;

    /** @var list<array{MetricEventType, string, ?int, ?string, \DateTimeImmutable}> */
    private array $events = [];

    /** @var array<string, array{hour: \DateTimeImmutable, route: string, class: string, requests: int, total: int, max: int, memory: int}> */
    private array $hourly = [];

    public function __construct(
        private readonly MetricsRepositoryInterface $repository,
        private readonly IpPseudonymizer $ips,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function event(MetricEventType $type, string $route, ?int $status = null, string $ip = ''): void
    {
        $this->events[] = [$type, self::route($route), $status, $this->ips->of($ip), $this->clock->now()];
    }

    public function request(string $route, int $status, int $durationMs, int $memoryPeakKb): void
    {
        $now = $this->clock->now();
        $hour = $now->setTime((int) $now->format('G'), 0, 0);
        $route = self::route($route);
        $class = intdiv($status, 100) . 'xx';
        $key = $hour->format('c') . '|' . $route . '|' . $class;

        $entry = $this->hourly[$key] ?? ['hour' => $hour, 'route' => $route, 'class' => $class, 'requests' => 0, 'total' => 0, 'max' => 0, 'memory' => 0];
        ++$entry['requests'];
        $entry['total'] += $durationMs;
        $entry['max'] = max($entry['max'], $durationMs);
        $entry['memory'] = max($entry['memory'], $memoryPeakKb);
        $this->hourly[$key] = $entry;
    }

    public function flush(): void
    {
        $events = $this->events;
        $hourly = $this->hourly;
        $this->events = [];
        $this->hourly = [];

        try {
            foreach ($events as [$type, $route, $status, $ipHash, $at]) {
                $this->repository->addEvent($type, $route, $status, $ipHash, $at);
            }
            foreach ($hourly as $h) {
                $this->repository->addHourly($h['hour'], $h['route'], $h['class'], $h['requests'], $h['total'], $h['max'], $h['memory']);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Mesures : écriture impossible', ['exception' => $e::class]);
        }
    }

    private static function route(string $route): string
    {
        return mb_substr($route, 0, self::ROUTE_MAX_LENGTH);
    }
}
