<?php

declare(strict_types=1);

namespace App\Accounting\Entity;

final class FiscalYear
{
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly \DateTimeImmutable $startsOn,
        public readonly \DateTimeImmutable $endsOn,
        public readonly FiscalYearStatus $status,
        public readonly int $openingBalanceCents,
        public readonly ?int $closingBalanceCents,
        public readonly int $nextEntryNumber,
        public readonly ?\DateTimeImmutable $approvedOn,
        public readonly int $revision,
    ) {
    }

    public function isOpen(): bool
    {
        return $this->status === FiscalYearStatus::Ouvert;
    }

    public function contains(\DateTimeImmutable $date): bool
    {
        return $date->format('Y-m-d') >= $this->startsOn->format('Y-m-d') && $date->format('Y-m-d') <= $this->endsOn->format('Y-m-d');
    }
}
