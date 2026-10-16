<?php

declare(strict_types=1);

namespace App\Accounting\Service\Dues;

/** État d'un adhérent pour un mois : payée, dispensée, à payer (mois atteint) ou à venir. */
enum CotisationCell: string
{
    case Payee = 'payee';
    case Dispensee = 'dispensee';
    case APayer = 'a_payer';
    case AVenir = 'a_venir';
}
