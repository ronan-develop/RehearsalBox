<?php

declare(strict_types=1);

namespace App\Service\Exception;

/** Lien de changement d'e-mail inconnu, expiré, déjà utilisé, ou qui ne peut plus aboutir : un seul message pour tous les cas. */
final class InvalidEmailChangeException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ce lien est invalide ou a expiré.');
    }
}
