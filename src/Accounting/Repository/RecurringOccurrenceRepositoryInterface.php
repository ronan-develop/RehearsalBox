<?php

declare(strict_types=1);

namespace App\Accounting\Repository;

/** Échéances décidées (confirmées ou abandonnées) : une seule décision par modèle et par mois, garantie par la clé unique de la base. */
interface RecurringOccurrenceRepositoryInterface
{
    /** Vrai si la décision est enregistrée, faux si cette échéance l'était déjà. */
    public function decide(int $templateId, \DateTimeImmutable $period, string $status, ?int $entryId, int $actorId, \DateTimeImmutable $now): bool;

    /**
     * @param list<int> $templateIds
     * @return array<string, true> clés `idModèle|AAAA-MM-01` des échéances déjà décidées
     */
    public function decidedFor(array $templateIds): array;
}
