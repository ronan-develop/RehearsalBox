<?php

declare(strict_types=1);

namespace App\Assembly\Exception;

/** Aucun compte rendu avec cet identifiant (le message ne le répète jamais). */
final class AssemblyNotFoundException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Compte rendu introuvable.');
    }
}
