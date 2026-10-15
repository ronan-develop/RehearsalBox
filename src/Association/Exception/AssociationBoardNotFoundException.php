<?php

declare(strict_types=1);

namespace App\Association\Exception;

/** Poste du bureau inconnu (le message ne répète jamais la valeur reçue). */
final class AssociationBoardNotFoundException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Poste introuvable.');
    }
}
