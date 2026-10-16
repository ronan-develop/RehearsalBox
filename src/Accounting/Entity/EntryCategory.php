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

    public function label(): string
    {
        return match ($this) {
            self::DroitsEntree => 'Droits d\'entrée',
            self::Cotisations => 'Cotisations',
            self::Subventions => 'Subventions',
            self::RevenusBiens => 'Revenus des biens et valeurs',
            self::Retributions => 'Rétributions pour services rendus',
            self::AutresRecettes => 'Autres recettes',
            self::Fournitures => 'Achats et fournitures',
            self::Materiel => 'Matériel',
            self::Loyer => 'Loyer',
            self::Electricite => 'Électricité',
            self::Assurances => 'Assurances',
            self::FraisBancaires => 'Frais bancaires',
            self::ImpotsTaxes => 'Impôts et taxes',
            self::AutresDepenses => 'Autres dépenses',
        };
    }

    /** @return list<self> les catégories d'un sens, dans l'ordre de la liste */
    public static function forDirection(Direction $direction): array
    {
        return array_values(array_filter(self::cases(), static fn (self $category): bool => $category->direction() === $direction));
    }
}
