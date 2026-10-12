<?php

declare(strict_types=1);

namespace App\Planning\Notification;

use App\Planning\Entity\SlotException;

/** Objet nul : scripts et tests qui n'envoient aucune notification. */
final class NoSlotExchangeNotifier implements SlotExchangeNotifierInterface
{
    public function requested(SlotException $exception): void
    {
    }

    public function decided(SlotException $exception): void
    {
    }
}
