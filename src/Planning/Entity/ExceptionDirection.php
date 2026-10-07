<?php

declare(strict_types=1);

namespace App\Planning\Entity;

enum ExceptionDirection: string
{
    case Recue = 'recue';
    case Envoyee = 'envoyee';
}
