<?php

declare(strict_types=1);

namespace App\Assembly\Entity;

/** Présence d'une personne à l'assemblée : présente, excusée ou représentée par un pouvoir. */
enum Attendance: string
{
    case Present = 'present';
    case Excuse = 'excuse';
    case Represente = 'represente';
}
