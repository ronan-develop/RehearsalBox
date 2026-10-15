<?php

declare(strict_types=1);

namespace App\Assembly\Entity;

/** Un point de l'ordre du jour et ce qui s'y est dit. */
final class AgendaItem
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $discussion,
    ) {
    }
}
