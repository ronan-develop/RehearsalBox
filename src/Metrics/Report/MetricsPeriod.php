<?php

declare(strict_types=1);

namespace App\Metrics\Report;

/** La période d'un rapport, choisie par un lien (`?periode=24h|7j|30j`) : pas de formulaire, pas de rafraîchissement continu. */
enum MetricsPeriod: string
{
    case Day = '24h';
    case Week = '7j';
    case Month = '30j';

    public static function fromQuery(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Day) : self::Day;
    }

    public function label(): string
    {
        return match ($this) {
            self::Day => '24 heures',
            self::Week => '7 jours',
            self::Month => '30 jours',
        };
    }

    /** Nombre d'heures couvertes. */
    public function hours(): int
    {
        return match ($this) {
            self::Day => 24,
            self::Week => 7 * 24,
            self::Month => 30 * 24,
        };
    }

    /** Un point par heure sur 24 h, un point par jour au-delà (une courbe de 720 points ne se lit pas). */
    public function bucketsByDay(): bool
    {
        return $this !== self::Day;
    }
}
