<?php

declare(strict_types=1);

namespace App\Assembly\Entity;

/** Type d'assemblée générale. */
enum AssemblyKind: string
{
    case Ordinaire = 'ordinaire';
    case Extraordinaire = 'extraordinaire';

    public function label(): string
    {
        return match ($this) {
            self::Ordinaire => 'ordinaire',
            self::Extraordinaire => 'extraordinaire',
        };
    }
}
