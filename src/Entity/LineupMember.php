<?php

declare(strict_types=1);

namespace App\Entity;

final class LineupMember
{
    public function __construct(
        private readonly string $name,
        private readonly string $instrument,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function instrument(): string
    {
        return $this->instrument;
    }

    /** @return array{name: string, instrument: string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'instrument' => $this->instrument];
    }

    /** @param array{name: string, instrument: string} $data */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['name'], (string) $data['instrument']);
    }
}
