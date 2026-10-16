<?php

declare(strict_types=1);

namespace App\Accounting\Entity;

/** Totaux d'un exercice, calculés à la lecture (jamais stockés) : recettes, dépenses et solde net par catégorie (contre-passations comprises). */
final class LedgerTotals
{
    /** @param array<string, int> $netByCategory solde net par valeur d'EntryCategory (recettes positives, dépenses négatives) */
    public function __construct(
        public readonly int $recettesCents,
        public readonly int $depensesCents,
        public readonly array $netByCategory,
    ) {
    }

    public function netCents(): int
    {
        return $this->recettesCents - $this->depensesCents;
    }
}
