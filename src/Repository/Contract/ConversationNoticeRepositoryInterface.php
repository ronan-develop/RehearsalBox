<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\DueReminder;

/**
 * Mémoire des e-mails envoyés au contact d'un groupe pour une conversation (#180) : un e-mail immédiat à la création, puis au
 * plus un rappel par message resté sans lecture. Aucune donnée personnelle : seulement (conversation, groupe, dates).
 */
interface ConversationNoticeRepositoryInterface
{
    /**
     * Réserve l'e-mail immédiat de ce groupe pour cette conversation. true si la réservation est nouvelle (on peut
     * envoyer), false si ce groupe a déjà été prévenu : atomique, deux appels concurrents n'envoient qu'un e-mail.
     */
    public function claimInitial(int $conversationId, int $groupId, \DateTimeImmutable $now): bool;

    /** Annule la réservation (envoi échoué) pour qu'un nouvel essai reste possible. */
    public function releaseInitial(int $conversationId, int $groupId): void;

    public function initialNotifiedAt(int $conversationId, int $groupId): ?\DateTimeImmutable;

    /**
     * Relances dues : un message d'un membre du groupe d'en face, ni système, créé entre $notBefore et $dueBefore, que
     * PERSONNE du groupe n'a lu depuis, et plus récent que le dernier rappel de ce groupe pour cette conversation.
     * Un message d'une personne membre des deux groupes (ou d'aucun) ne compte pour aucun côté.
     *
     * @return list<DueReminder> le plus ancien d'abord
     */
    public function findDueReminders(\DateTimeImmutable $dueBefore, \DateTimeImmutable $notBefore): array;

    public function remindedAt(int $conversationId, int $groupId): ?\DateTimeImmutable;

    /**
     * Réserve la relance : atomique, et refusée si ce groupe vient d'être relancé (moins d'une heure) pour que deux
     * exécutions qui se chevauchent n'envoient qu'un e-mail.
     */
    public function claimReminder(int $conversationId, int $groupId, \DateTimeImmutable $now): bool;

    /** Annule la réservation (envoi échoué) en rétablissant la date précédente. */
    public function restoreReminder(int $conversationId, int $groupId, ?\DateTimeImmutable $previous): void;
}
