<?php

declare(strict_types=1);

namespace App\Accounting\Entity;

/** Une ligne du journal. Immuable : aucune modification ni suppression n'existe nulle part. */
final class AccountingEntry
{
    public function __construct(
        public readonly int $id,
        public readonly int $fiscalYearId,
        public readonly int $number,
        public readonly \DateTimeImmutable $occurredOn,
        public readonly string $label,
        public readonly int $amountCents,
        public readonly Direction $direction,
        public readonly EntryCategory $category,
        public readonly PaymentMethod $paymentMethod,
        public readonly ?string $voucherRef,
        public readonly ?string $note,
        public readonly ?int $reversesEntryId,
    ) {
    }
}
