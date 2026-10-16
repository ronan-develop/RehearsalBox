<?php

declare(strict_types=1);

namespace App\Accounting\Entity;

/** Vie d'un exercice : ouvert (saisie), clôturé (figé, solde final posé), approuvé en assemblée générale (date posée). Jamais de retour en arrière. */
enum FiscalYearStatus: string
{
    case Ouvert = 'ouvert';
    case Cloture = 'cloture';
    case Approuve = 'approuve';
}
