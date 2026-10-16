<?php

declare(strict_types=1);

namespace App\Accounting\Exception;

/** Aucun modèle récurrent avec cet identifiant (le message ne le répète jamais). */
final class RecurringTemplateNotFoundException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Modèle introuvable.');
    }
}
