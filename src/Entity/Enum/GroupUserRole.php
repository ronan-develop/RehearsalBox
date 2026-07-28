<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum GroupUserRole: string
{
    case Gestionnaire = 'gestionnaire';
    case Membre = 'membre';
}
