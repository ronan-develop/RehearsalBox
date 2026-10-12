<?php

declare(strict_types=1);

namespace App\Planning\Notification;

use App\Planning\Entity\SlotException;

/** Notifications des demandes d'échange de créneau (#455) : jamais bloquantes, jamais de désabonnement général. */
interface SlotExchangeNotifierInterface
{
    /** Une demande vient d'être faite : le groupe titulaire du créneau en est prévenu, à son adresse de contact. */
    public function requested(SlotException $exception): void;

    /** Le groupe titulaire vient d'accepter ou de refuser : la personne qui a fait la demande en est prévenue. */
    public function decided(SlotException $exception): void;
}
