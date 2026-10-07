<?php

declare(strict_types=1);

namespace App\Account\Repository;

/** Préférence d'une personne : recevoir ou non les e-mails de notification individuels (mentions). */
interface NotificationPreferenceRepositoryInterface
{
    /** Vrai par défaut ; faux si la personne est désinscrite ou inconnue. */
    public function emailEnabled(int $userId): bool;

    public function setEmailEnabled(int $userId, bool $enabled): void;
}
