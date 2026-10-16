<?php

declare(strict_types=1);

namespace App\Accounting\Exception;

/** L'exercice n'est pas dans l'état attendu (par exemple clos : plus de saisie datée dedans). */
final class FiscalYearLockedException extends \RuntimeException
{
    public function __construct(string $message = 'Cet exercice n\'est plus ouvert : aucune écriture n\'y est possible.')
    {
        parent::__construct($message);
    }
}
