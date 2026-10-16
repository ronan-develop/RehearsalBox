<?php

declare(strict_types=1);

namespace App\Accounting\Exception;

/** L'exercice a changé depuis la lecture (révision périmée) : à recharger avant de recommencer. */
final class FiscalYearChangedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cet exercice a été modifié entre-temps : rechargez la page.');
    }
}
