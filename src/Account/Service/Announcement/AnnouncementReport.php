<?php

declare(strict_types=1);

namespace App\Account\Service\Announcement;

/** Bilan d'un passage de l'annonce (#444). Aucune adresse : seulement des comptes. */
final class AnnouncementReport
{
    public function __construct(
        private readonly int $sent,
        private readonly int $failed,
        private readonly int $alreadySent,
        private readonly int $pending,
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

    public function alreadySent(): int
    {
        return $this->alreadySent;
    }

    /** Comptes à qui l'annonce partirait lors d'un envoi réel. */
    public function pending(): int
    {
        return $this->pending;
    }
}
