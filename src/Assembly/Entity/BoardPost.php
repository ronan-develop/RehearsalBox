<?php

declare(strict_types=1);

namespace App\Assembly\Entity;

/** Poste obligatoire du bureau de l'association. */
enum BoardPost: string
{
    case President = 'president';
    case Tresorier = 'tresorier';
    case Secretaire = 'secretaire';

    public function label(): string
    {
        return match ($this) {
            self::President => 'Président',
            self::Tresorier => 'Trésorier',
            self::Secretaire => 'Secrétaire',
        };
    }
}
