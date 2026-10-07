<?php

declare(strict_types=1);

namespace App\Metrics\Report;

/**
 * Les points d'un graphique (#196) : une heure sur 24 h, un jour au-delà, en HEURE LOCALE, pour une période qui se termine
 * maintenant. Les mesures sont stockées en UTC : `keyOf()` convertit une heure UTC lue en base vers la clé de son point.
 */
final class TimeBuckets
{
    /** @var array<string, string> clé du point => étiquette, dans l'ordre */
    private array $labels = [];

    private \DateTimeImmutable $start;

    public function __construct(private readonly MetricsPeriod $period, \DateTimeImmutable $now, private readonly \DateTimeZone $local)
    {
        $localNow = $now->setTimezone($local);

        if (!$period->bucketsByDay()) {
            $this->start = $localNow->setTime((int) $localNow->format('G'), 0)->modify('-23 hours');
            for ($i = 0; $i < 24; ++$i) {
                $at = $this->start->setTimestamp($this->start->getTimestamp() + $i * 3600);
                $this->labels[$at->format('Y-m-d H')] = $at->format('G') . ' h';
            }

            return;
        }

        $days = intdiv($period->hours(), 24);
        $this->start = $localNow->setTime(0, 0)->modify('-' . ($days - 1) . ' days');
        for ($i = 0; $i < $days; ++$i) {
            $at = $this->start->modify('+' . $i . ' days');
            $this->labels[$at->format('Y-m-d')] = $at->format('d/m');
        }
    }

    /** Début de la fenêtre, en UTC : de quoi interroger la base. */
    public function sinceUtc(): \DateTimeImmutable
    {
        return $this->start->setTimezone(new \DateTimeZone('UTC'));
    }

    /** @return list<string> */
    public function labels(): array
    {
        return array_values($this->labels);
    }

    /** @return array<string, int> une série à zéro, une valeur par point */
    public function emptySeries(): array
    {
        return array_fill_keys(array_keys($this->labels), 0);
    }

    /** Clé du point d'une heure UTC lue en base ; null si elle tombe hors de la fenêtre. */
    public function keyOf(string $utcHour): ?string
    {
        $local = (new \DateTimeImmutable($utcHour, new \DateTimeZone('UTC')))->setTimezone($this->local);
        $key = $local->format($this->period->bucketsByDay() ? 'Y-m-d' : 'Y-m-d H');

        return isset($this->labels[$key]) ? $key : null;
    }
}
