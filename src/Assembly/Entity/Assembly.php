<?php

declare(strict_types=1);

namespace App\Assembly\Entity;

/** Une version d'un compte rendu d'assemblée générale (#361) : l'en-tête et les rapports ; le contenu est dans AssemblyContent. */
final class Assembly
{
    /** Heures au format `HH:MM`. */
    public function __construct(
        public readonly int $id,
        public readonly AssemblyKind $kind,
        public readonly \DateTimeImmutable $heldOn,
        public readonly ?string $location,
        public readonly ?string $openedAt,
        public readonly ?string $closedAt,
        public readonly ?bool $quorumReached,
        public readonly ?string $moralReport,
        public readonly ?string $financialReport,
        public readonly ?string $otherBusiness,
        public readonly AssemblyStatus $status,
        public readonly int $version,
        public readonly ?int $previousId,
        public readonly ?string $correctionReason,
        public readonly int $revision,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $validatedAt,
    ) {
    }

    public function isDraft(): bool
    {
        return $this->status === AssemblyStatus::Brouillon;
    }
}
