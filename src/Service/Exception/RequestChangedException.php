<?php

declare(strict_types=1);

namespace App\Service\Exception;

/** Le groupe demandeur a modifié sa demande après que le titulaire l'a consultée : sa réponse ne vaut pas pour la nouvelle date. */
final class RequestChangedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('La demande a été modifiée par le groupe demandeur : rechargez la page pour voir la nouvelle date avant de répondre.');
    }
}
