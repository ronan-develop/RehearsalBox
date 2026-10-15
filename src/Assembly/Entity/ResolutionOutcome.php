<?php

declare(strict_types=1);

namespace App\Assembly\Entity;

/** Résultat du vote d'une résolution. */
enum ResolutionOutcome: string
{
    case Adoptee = 'adoptee';
    case Rejetee = 'rejetee';
}
