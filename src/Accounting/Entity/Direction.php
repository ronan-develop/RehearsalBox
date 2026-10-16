<?php

declare(strict_types=1);

namespace App\Accounting\Entity;

/** Sens d'une écriture : argent reçu ou argent dépensé. */
enum Direction: string
{
    case Recette = 'recette';
    case Depense = 'depense';

    public function opposite(): self
    {
        return $this === self::Recette ? self::Depense : self::Recette;
    }
}
