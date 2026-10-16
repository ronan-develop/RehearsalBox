<?php

declare(strict_types=1);

namespace App\Accounting\Entity;

/** Modèle d'écriture récurrente (#362) : ce qui se répète (loyer, électricité…). Montant null = variable, à saisir à chaque échéance. */
final class RecurringTemplate
{
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly Direction $direction,
        public readonly EntryCategory $category,
        public readonly PaymentMethod $paymentMethod,
        public readonly ?int $amountCents,
        public readonly ?string $voucherRef,
        public readonly Periodicity $periodicity,
        public readonly int $dueDay,
        public readonly \DateTimeImmutable $firstMonth,
        public readonly bool $isActive,
    ) {
    }

    public function isVariable(): bool
    {
        return $this->amountCents === null;
    }
}
