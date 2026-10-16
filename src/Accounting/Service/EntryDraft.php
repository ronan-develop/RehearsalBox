<?php

declare(strict_types=1);

namespace App\Accounting\Service;

use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\PaymentMethod;

/** Écriture validée par la politique d'entrée, prête à être numérotée et écrite. */
final class EntryDraft
{
    public function __construct(
        public readonly \DateTimeImmutable $occurredOn,
        public readonly string $label,
        public readonly int $amountCents,
        public readonly Direction $direction,
        public readonly EntryCategory $category,
        public readonly PaymentMethod $paymentMethod,
        public readonly ?string $voucherRef,
        public readonly ?string $note,
    ) {
    }
}
