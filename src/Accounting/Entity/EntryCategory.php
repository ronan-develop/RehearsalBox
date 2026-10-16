<?php

declare(strict_types=1);

namespace App\Accounting\Entity;

/**
 * Catégories d'écritures (liste fermée, #362). Recettes : les rubriques de l'article 8 des statuts. Dépenses : à confirmer avec le
 * trésorier. Une écriture normale doit avoir une catégorie du bon sens ; une contre-passation garde la catégorie de l'original.
 */
enum EntryCategory: string
{
    case DroitsEntree = 'droits_entree';
    case Cotisations = 'cotisations';
    case Subventions = 'subventions';
    case RevenusBiens = 'revenus_biens';
    case Retributions = 'retributions';
    case AutresRecettes = 'autres_recettes';
    case Fournitures = 'fournitures';
    case Materiel = 'materiel';
    case Loyer = 'loyer';
    case Electricite = 'electricite';
    case Assurances = 'assurances';
    case FraisBancaires = 'frais_bancaires';
    case ImpotsTaxes = 'impots_taxes';
    case AutresDepenses = 'autres_depenses';

    public function direction(): Direction
    {
        return match ($this) {
            self::DroitsEntree, self::Cotisations, self::Subventions, self::RevenusBiens, self::Retributions, self::AutresRecettes => Direction::Recette,
            default => Direction::Depense,
        };
    }
}
