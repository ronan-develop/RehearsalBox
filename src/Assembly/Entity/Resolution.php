<?php

declare(strict_types=1);

namespace App\Assembly\Entity;

/** Une résolution soumise au vote : totaux anonymes seulement, jamais de vote nominatif. */
final class Resolution
{
    public function __construct(
        public readonly string $text,
        public readonly int $for,
        public readonly int $against,
        public readonly int $abstain,
        public readonly ?ResolutionOutcome $outcome,
    ) {
    }
}
