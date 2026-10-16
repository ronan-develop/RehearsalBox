<?php

declare(strict_types=1);

namespace App\Accounting\Entity;

/** Relevé de compte archivé (#362) : métadonnées seulement, le fichier est stocké hors de la racine web sous `storedName`. */
final class Statement
{
    public function __construct(
        public readonly int $id,
        public readonly int $fiscalYearId,
        public readonly \DateTimeImmutable $periodStart,
        public readonly \DateTimeImmutable $periodEnd,
        public readonly ?int $closingBalanceCents,
        public readonly string $originalName,
        public readonly string $storedName,
        public readonly string $mime,
        public readonly int $sizeBytes,
        public readonly string $sha256,
        public readonly ?int $replacedById,
        public readonly \DateTimeImmutable $uploadedAt,
    ) {
    }

    public function isReplaced(): bool
    {
        return $this->replacedById !== null;
    }
}
