<?php

declare(strict_types=1);

namespace App\Account\Exception;

/** Jeton de réinitialisation inconnu, expiré ou déjà utilisé (volontairement indistinguables). */
final class InvalidResetTokenException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Lien de réinitialisation invalide ou expiré.');
    }
}
