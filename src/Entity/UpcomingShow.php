<?php

declare(strict_types=1);

namespace App\Entity;

final class UpcomingShow
{
    public function __construct(
        private readonly string $date,
        private readonly string $venue,
    ) {
    }

    public function date(): string
    {
        return $this->date;
    }

    public function venue(): string
    {
        return $this->venue;
    }

    /** @return array{date: string, venue: string} */
    public function toArray(): array
    {
        return ['date' => $this->date, 'venue' => $this->venue];
    }

    /** @param array{date: string, venue: string} $data */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['date'], (string) $data['venue']);
    }
}
