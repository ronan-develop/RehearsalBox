<?php

declare(strict_types=1);

namespace App\Accounting\Exception;

/** Aucun relevé avec cet identifiant, ou fichier absent du stockage (le message ne répète jamais l'identifiant). */
final class StatementNotFoundException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Relevé introuvable.');
    }
}
