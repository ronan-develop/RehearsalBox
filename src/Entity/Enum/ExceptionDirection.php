<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum ExceptionDirection: string
{
    case Recue = 'recue';
    case Envoyee = 'envoyee';
}
