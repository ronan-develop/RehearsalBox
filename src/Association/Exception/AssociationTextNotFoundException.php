<?php

declare(strict_types=1);

namespace App\Association\Exception;

/** Aucun texte avec cet identifiant (le message ne le répète jamais). */
final class AssociationTextNotFoundException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Texte introuvable.');
    }
}
