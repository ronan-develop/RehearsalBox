<?php

declare(strict_types=1);

namespace App\Accounting\Service;

use App\Accounting\Entity\Statement;

/** Un relevé et son contrôle : solde de la banque selon le livre à la fin de la période, et écart avec le solde du relevé (sans solde saisi : pas d'écart). */
final class StatementReview
{
    public function __construct(
        public readonly Statement $statement,
        public readonly int $ledgerBalanceCents,
        public readonly ?int $gapCents,
    ) {
    }
}
