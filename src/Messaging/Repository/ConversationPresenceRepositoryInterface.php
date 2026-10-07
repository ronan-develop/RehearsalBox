<?php

declare(strict_types=1);

namespace App\Messaging\Repository;

/** Présence dans une conversation : dernière lecture, « en train d'écrire » et « vu par ». */
interface ConversationPresenceRepositoryInterface
{
    /** Date de dernière lecture de la personne, null si elle n'a jamais ouvert la conversation. */
    public function lastReadAt(int $conversationId, int $userId): ?\DateTimeImmutable;

    public function markRead(int $conversationId, int $userId, \DateTimeImmutable $now): void;

    /** Signale que la personne est en train d'écrire ; au plus un signal pris en compte toutes les 2 secondes. */
    public function setTyping(int $conversationId, int $userId, \DateTimeImmutable $now): void;

    /** @return list<string> noms des autres membres qui écrivent depuis $since, par ordre alphabétique */
    public function typingNames(int $conversationId, int $exceptUserId, \DateTimeImmutable $since): array;

    /**
     * Membres (hors $exceptUserId) qui ont lu la conversation à partir de $messageDate : ils ont vu ce message.
     *
     * @return list<string> noms par ordre alphabétique
     */
    public function readersOf(int $conversationId, \DateTimeImmutable $messageDate, int $exceptUserId): array;
}
