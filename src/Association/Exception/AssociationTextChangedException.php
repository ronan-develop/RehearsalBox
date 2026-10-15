<?php

declare(strict_types=1);

namespace App\Association\Exception;

/** Le texte a été modifié par un autre administrateur depuis que la page l'a lu : rien n'est écrit. */
final class AssociationTextChangedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ce texte a été modifié entre-temps : rechargez la page.');
    }
}
