<?php

declare(strict_types=1);

namespace App\Repository\Exception;

/** Une demande existe déjà pour ce créneau à cette date (clé d'unicité de la base) : un conflit, pas une erreur SQL. */
final class DuplicateOccurrenceException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Une demande existe déjà pour ce créneau à cette date.');
    }
}
