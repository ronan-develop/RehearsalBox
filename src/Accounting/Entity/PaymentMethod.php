<?php

declare(strict_types=1);

namespace App\Accounting\Entity;

/** Moyen de paiement : espèces = caisse ; virement, chèque et carte = banque. */
enum PaymentMethod: string
{
    case Especes = 'especes';
    case Virement = 'virement';
    case Cheque = 'cheque';
    case Carte = 'carte';
}
