<?php

declare(strict_types=1);

namespace App\Group\Entity;

enum GroupUserRole: string
{
    case Gestionnaire = 'gestionnaire';
    case Membre = 'membre';
}
