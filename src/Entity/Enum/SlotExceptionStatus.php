<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum SlotExceptionStatus: string
{
    case Liberee = 'liberee';
    case Revendiquee = 'revendiquee';
    case Expiree = 'expiree';
    case Annulee = 'annulee';
}
