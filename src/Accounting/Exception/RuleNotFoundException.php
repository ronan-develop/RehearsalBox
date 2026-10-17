<?php

declare(strict_types=1);

namespace App\Accounting\Exception;

/** Aucune règle de libellé avec cet identifiant (le message ne le répète jamais). */
final class RuleNotFoundException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Règle introuvable.');
    }
}
