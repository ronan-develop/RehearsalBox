<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum UserRole: string
{
    case Admin = 'admin';
    case Musicien = 'musicien';
}
