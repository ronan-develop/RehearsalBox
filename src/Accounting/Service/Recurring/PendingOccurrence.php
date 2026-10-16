<?php

declare(strict_types=1);

namespace App\Accounting\Service\Recurring;

use App\Accounting\Entity\RecurringTemplate;

/** Une échéance de modèle à confirmer ou à abandonner : `period` (premier jour du mois) l'identifie avec le modèle. */
final class PendingOccurrence
{
    public function __construct(
        public readonly RecurringTemplate $template,
        public readonly \DateTimeImmutable $period,
        public readonly \DateTimeImmutable $dueOn,
    ) {
    }
}
