<?php

declare(strict_types=1);

namespace App\Planning\Entity;

/** État d'une réservation libre (#263) : en attente d'un administrateur, puis validée ou refusée ; annulée par le demandeur. */
enum FreeSlotBookingStatus: string
{
    case EnAttente = 'en_attente';
    case Validee = 'validee';
    case Refusee = 'refusee';
    case Annulee = 'annulee';
}
