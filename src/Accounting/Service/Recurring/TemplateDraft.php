<?php

declare(strict_types=1);

namespace App\Accounting\Service\Recurring;

use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\PaymentMethod;
use App\Accounting\Entity\Periodicity;

/** Modèle validé par la politique d'entrée, prêt à être enregistré. */
final class TemplateDraft
{
    public function __construct(
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
}
