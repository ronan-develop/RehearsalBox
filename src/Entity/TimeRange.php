<?php

declare(strict_types=1);

namespace App\Entity;

/** Plage horaire d'une même journée (#263), début strictement avant la fin ; valeur immuable, heures au format HH:MM:SS. */
final class TimeRange
{
    public function __construct(private readonly string $start, private readonly string $end)
    {
        if ($end <= $start) {
            throw new \InvalidArgumentException('La fin d’une plage doit être après son début.');
        }
    }

    /** Les deux colonnes TIME d'une ligne : null quand elles sont vides (pas de plage, tout le créneau). */
    public static function fromColumns(?string $start, ?string $end): ?self
    {
        return $start === null || $end === null ? null : new self($start, $end);
    }

    public function start(): string
    {
        return $this->start;
    }

    public function end(): string
    {
        return $this->end;
    }

    /** Vrai si cette plage tient entièrement dans $other (bornes comprises). */
    public function isWithin(self $other): bool
    {
        return $this->start >= $other->start && $this->end <= $other->end;
    }
}
