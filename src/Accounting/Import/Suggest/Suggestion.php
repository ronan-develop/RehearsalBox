<?php

declare(strict_types=1);

namespace App\Accounting\Import\Suggest;

use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\PaymentMethod;

/**
 * Proposition pour une ligne en attente (#362) : une AIDE à la relecture, jamais une décision. `source` dit d'où elle vient, en mots simples ;
 * `occurrence` (modèle récurrent) et `memberUserId` + `periodMonth` (cotisation) sont revérifiés par le serveur à la validation.
 */
final class Suggestion
{
    public function __construct(
        public readonly string $source,
        public readonly ?EntryCategory $category = null,
        public readonly ?PaymentMethod $paymentMethod = null,
        public readonly ?string $voucherRef = null,
        public readonly ?string $occurrence = null,
        public readonly ?int $memberUserId = null,
        public readonly ?string $periodMonth = null,
        public readonly ?string $label = null,
    ) {
    }
}
