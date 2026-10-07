<?php

declare(strict_types=1);

namespace App\Account\Entity;

enum UserRole: string
{
    case Admin = 'admin';
    case Musicien = 'musicien';
}
