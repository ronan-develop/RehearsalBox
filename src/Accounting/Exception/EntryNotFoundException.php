<?php

declare(strict_types=1);

namespace App\Accounting\Exception;

/** Aucune écriture avec cet identifiant (le message ne le répète jamais). */
final class EntryNotFoundException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Écriture introuvable.');
    }
}
