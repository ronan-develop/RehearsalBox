<?php

declare(strict_types=1);

namespace App\Accounting\Import;

use App\Accounting\Entity\Direction;

/** Ligne en salle d'attente (#362) : ce que la banque a envoyé, pas encore une écriture. Le libellé peut porter des noms de tiers ou un IBAN. */
final class ImportLine
{
    public const PENDING = 'en_attente';
    public const VALIDATED = 'validee';
    public const DISCARDED = 'ecartee';

    public function __construct(
        public readonly int $id,
        public readonly int $batchId,
        public readonly int $recordNumber,
        public readonly \DateTimeImmutable $occurredOn,
        public readonly string $label,
        public readonly int $amountCents,
        public readonly Direction $direction,
        public readonly string $status,
        public readonly ?int $entryId,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
