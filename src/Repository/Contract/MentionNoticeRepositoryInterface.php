<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\DueMentionReminder;
use App\Entity\MentionNotice;

/** Suivi des e-mails de mention (#178) : 24 h minimum entre deux e-mails par (conversation, personne), une relance, un plafond par auteur. */
interface MentionNoticeRepositoryInterface
{
    /**
     * Réserve l'envoi d'un e-mail de mention, atomiquement : refusé si un e-mail est parti pour cette personne dans cette
     * conversation après $notBefore. Un nouvel e-mail remet à zéro la relance.
     */
    public function claimNotice(int $conversationId, int $userId, int $byUserId, \DateTimeImmutable $now, \DateTimeImmutable $notBefore): bool;

    public function find(int $conversationId, int $userId): ?MentionNotice;

    /** Annule une réservation (échec d'envoi) : remet l'état précédent, ou supprime la ligne s'il n'y en avait pas. */
    public function restoreNotice(int $conversationId, int $userId, ?MentionNotice $previous): void;

    /** E-mails de mention envoyés par cet auteur depuis $since (plafond anti-abus). */
    public function countSentBy(int $byUserId, \DateTimeImmutable $since): int;

    /**
     * Relances dues : e-mail de mention parti entre $notBefore et $dueBefore, jamais relancé, mention toujours non lue,
     * personne active, abonnée et toujours participante, conversation hors corbeille.
     *
     * @return list<DueMentionReminder>
     */
    public function findDueReminders(\DateTimeImmutable $dueBefore, \DateTimeImmutable $notBefore): array;

    /** Réserve la relance (une seule par e-mail de mention). */
    public function claimReminder(int $conversationId, int $userId, \DateTimeImmutable $now): bool;

    public function restoreReminder(int $conversationId, int $userId): void;
}
