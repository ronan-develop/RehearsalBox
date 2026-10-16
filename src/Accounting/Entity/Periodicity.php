<?php

declare(strict_types=1);

namespace App\Accounting\Entity;

/** Rythme d'une écriture récurrente : une échéance tous les 1, 3 ou 12 mois à partir du premier mois du modèle. */
enum Periodicity: string
{
    case Mensuelle = 'mensuelle';
    case Trimestrielle = 'trimestrielle';
    case Annuelle = 'annuelle';

    public function months(): int
    {
        return match ($this) {
            self::Mensuelle => 1,
            self::Trimestrielle => 3,
            self::Annuelle => 12,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Mensuelle => 'Chaque mois',
            self::Trimestrielle => 'Chaque trimestre',
            self::Annuelle => 'Chaque année',
        };
    }
}
