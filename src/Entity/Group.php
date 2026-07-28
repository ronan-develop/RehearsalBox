<?php

declare(strict_types=1);

namespace App\Entity;

final class Group
{
    /**
     * @param list<LineupMember> $lineup
     * @param list<UpcomingShow> $upcomingShows
     */
    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly ?string $genre,
        private readonly ?string $colorHex,
        private readonly string $contactEmail,
        private readonly array $lineup = [],
        private readonly array $upcomingShows = [],
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function genre(): ?string
    {
        return $this->genre;
    }

    public function colorHex(): ?string
    {
        return $this->colorHex;
    }

    public function contactEmail(): string
    {
        return $this->contactEmail;
    }

    /** @return list<LineupMember> */
    public function lineup(): array
    {
        return $this->lineup;
    }

    /** @return list<UpcomingShow> */
    public function upcomingShows(): array
    {
        return $this->upcomingShows;
    }
}
