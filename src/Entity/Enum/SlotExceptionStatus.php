<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum SlotExceptionStatus: string
{
    case EnAttente = 'en_attente';
    case Acceptee = 'acceptee';
    case Refusee = 'refusee';
    case Expiree = 'expiree';
}
