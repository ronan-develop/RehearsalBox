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

    /** La partie commune à deux plages ; null si elles ne se chevauchent pas (des bornes qui se touchent ne partagent rien). */
    public function intersect(self $other): ?self
    {
        $start = max($this->start, $other->start);
        $end = min($this->end, $other->end);

        return $start < $end ? new self($start, $end) : null;
    }

    /**
     * Ce qui reste de cette plage une fois retirées les autres, dans l'ordre : les plages retirées peuvent être dans le désordre,
     * se chevaucher ou se toucher.
     *
     * @param list<self> $others
     *
     * @return list<self>
     */
    public function subtract(array $others): array
    {
        usort($others, static fn (self $a, self $b): int => $a->start <=> $b->start);

        $free = [];
        $cursor = $this->start;
        foreach ($others as $other) {
            if ($other->end <= $cursor || $other->start >= $this->end) {
                continue;
            }
            if ($other->start > $cursor) {
                $free[] = new self($cursor, $other->start);
            }
            $cursor = max($cursor, $other->end);
        }
        if ($cursor < $this->end) {
            $free[] = new self($cursor, $this->end);
        }

        return $free;
    }
}

