<?php

declare(strict_types=1);

namespace App\Accounting\Exception;

/** Aucun exercice avec cet identifiant (le message ne le répète jamais). */
final class FiscalYearNotFoundException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Exercice introuvable.');
    }
}
