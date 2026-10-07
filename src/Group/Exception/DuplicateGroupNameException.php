<?php

declare(strict_types=1);

namespace App\Group\Exception;

/** Un autre groupe porte déjà ce nom (clé d'unicité de la base, insensible à la casse et aux accents) : un conflit, pas une erreur SQL. */
final class DuplicateGroupNameException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Un groupe porte déjà ce nom.');
    }
}
