<?php

declare(strict_types=1);

namespace App\Operations\Backup;

/** État du fichier de clés du chiffrement des messages, tel que la page d'exploitation peut le dire sans jamais l'exposer. */
enum KeyFileState: string
{
    case Ok = 'ok';
    case Missing = 'missing';
    case TooOpen = 'too-open';
    case Unreadable = 'unreadable';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'présent, lisible, droits stricts',
            self::Missing => 'absent',
            self::TooOpen => 'droits trop ouverts (chmod 600 attendu)',
            self::Unreadable => 'illisible ou invalide',
        };
    }
}
