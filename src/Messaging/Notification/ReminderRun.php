<?php

declare(strict_types=1);

namespace App\Messaging\Notification;

use App\Support\DaytimeWindow;

/**
 * Ce que les deux relances (groupes de la messagerie #180, personnes mentionnées #178) partagent (#238) : l'instant de l'exécution, la
 * fenêtre d'âge (pas avant 24 h, plus au-delà de 7 jours), la plage de jour locale et les compteurs du bilan. Une instance par
 * exécution. Ni boucle générique ni « template method » : chaque relance garde sa propre réservation, son envoi et sa restauration.
 */
final class ReminderRun
{
    public const MIN_AGE = '-24 hours';
    public const MAX_AGE = '-7 days';

    private int $sent = 0;
    private int $failed = 0;
    private int $skipped = 0;

    public function __construct(
        private readonly \DateTimeImmutable $now,
        private readonly \DateTimeZone $localTimezone,
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    /** Une relance n'est due qu'une fois le message ou l'e-mail précédent vieux d'au moins 24 h : cet instant est sa borne récente. */
    public function notBefore(): \DateTimeImmutable
    {
        return $this->now->modify(self::MIN_AGE);
    }

    /** Au-delà de 7 jours, plus de relance (évite une relance tardive après une interruption). */
    public function notAfter(): \DateTimeImmutable
    {
        return $this->now->modify(self::MAX_AGE);
    }

    public function inDaytime(): bool
    {
        return DaytimeWindow::contains($this->now, $this->localTimezone);
    }

    public function markSent(): void
    {
        ++$this->sent;
    }

    public function markFailed(): void
    {
        ++$this->failed;
    }

    public function markSkipped(): void
    {
        ++$this->skipped;
    }

    public function report(): ReminderReport
    {
        return new ReminderReport($this->sent, $this->failed, $this->skipped);
    }

    public function outsideWindowReport(): ReminderReport
    {
        return new ReminderReport(outsideWindow: true);
    }
}
