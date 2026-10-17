<?php

declare(strict_types=1);

namespace App\Accounting\Repository\Notice;

/** Dernier rappel parti par canal (`treasurer`, `president`) : au plus un e-mail par intervalle, même si le cron tourne toutes les heures. */
interface ReminderNoticeRepositoryInterface
{
    /**
     * Réserve l'envoi, atomiquement : refusé (null) si un e-mail est parti pour ce canal après `$notBefore`. Rend la date précédente
     * (le début des temps si jamais) à redonner à `release` si l'envoi échoue.
     */
    public function claim(string $channel, \DateTimeImmutable $now, \DateTimeImmutable $notBefore): ?\DateTimeImmutable;

    /** Annule une réservation (échec d'envoi) : remet la date précédente, seulement si elle est toujours celle que `claim` a posée. */
    public function release(string $channel, \DateTimeImmutable $claimedAt, \DateTimeImmutable $previous): void;
}
