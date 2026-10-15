<?php

declare(strict_types=1);

namespace App\Assembly\Exception;

/** Compte rendu déjà validé, modifié entre-temps par un autre administrateur, ou correction déjà ouverte. */
final class AssemblyLockedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ce compte rendu a été modifié ou validé entre-temps : rechargez la page.');
    }
}
