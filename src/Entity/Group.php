<?php

declare(strict_types=1);

namespace App\Entity;

final class Group
{
    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly ?string $genre,
        private readonly ?string $colorHex,
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
}
