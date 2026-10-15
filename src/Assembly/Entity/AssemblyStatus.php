<?php

declare(strict_types=1);

namespace App\Assembly\Entity;

/** État d'un compte rendu (#361) : brouillon modifiable, puis validé et figé. */
enum AssemblyStatus: string
{
    case Brouillon = 'brouillon';
    case Valide = 'valide';
}
