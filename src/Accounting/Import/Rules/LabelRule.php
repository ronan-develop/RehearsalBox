<?php

declare(strict_types=1);

namespace App\Accounting\Import\Rules;

use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\PaymentMethod;

/** Règle explicite de la mémoire des libellés (#362) : un libellé normalisé qui contient le motif reçoit ce sens, cette catégorie et ce moyen de paiement. */
final class LabelRule
{
    public function __construct(
        public readonly int $id,
        public readonly string $pattern,
        public readonly Direction $direction,
        public readonly EntryCategory $category,
        public readonly PaymentMethod $paymentMethod,
    ) {
    }
}
