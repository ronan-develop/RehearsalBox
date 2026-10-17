<?php

declare(strict_types=1);

namespace App\Accounting\Import\Parse;

use App\Accounting\Entity\Direction;

/** Une ligne lue dans le fichier : prête à être mise en attente, jamais encore une écriture. Montant en centimes entiers, toujours positif. */
final class ParsedLine
{
    public function __construct(
        public readonly int $recordNumber,
        public readonly \DateTimeImmutable $date,
        public readonly string $label,
        public readonly int $amountCents,
        public readonly Direction $direction,
    ) {
    }
}
