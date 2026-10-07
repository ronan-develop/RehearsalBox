<?php

declare(strict_types=1);

namespace App\Messaging\Notification;

/** Bilan d'une exécution des relances : aucune adresse, aucun contenu. */
final class ReminderReport
{
    public function __construct(
        private readonly int $sent = 0,
        private readonly int $failed = 0,
        private readonly int $skipped = 0,
        private readonly bool $outsideWindow = false,
    ) {
    }

    public function sent(): int
    {
        return $this->sent;
    }

    public function failed(): int
    {
        return $this->failed;
    }

    /** Relances ignorées (adresse de contact invalide, déjà réservée par une autre exécution). */
    public function skipped(): int
    {
        return $this->skipped;
    }

    /** Hors de la plage de jour : rien n'est parti, les relances dues attendent le matin. */
    public function outsideWindow(): bool
    {
        return $this->outsideWindow;
    }
}
