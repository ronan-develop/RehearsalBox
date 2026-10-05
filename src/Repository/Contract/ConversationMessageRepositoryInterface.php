<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\ConversationMessage;

/** Messages d'une conversation : écriture, lecture, correction (#200) et comptages pour la limite de débit. */
interface ConversationMessageRepositoryInterface
{
    /** @param int|null $replyToId message de la même conversation cité par celui-ci (déjà vérifié par l'appelant) */
    public function addMessage(int $conversationId, int $authorId, string $body, \DateTimeImmutable $now, bool $system = false, ?int $replyToId = null): ConversationMessage;

    /** Un message précis de la conversation (ancre d'une lecture incrémentale), null s'il n'en fait pas partie. */
    public function messageById(int $conversationId, int $messageId): ?ConversationMessage;

    /** Dernier message ordinaire (hors lignes système) écrit par la personne dans la conversation. */
    public function lastMessageBy(int $conversationId, int $authorId): ?ConversationMessage;

    /**
     * @param int $afterId ne renvoie que les messages d'identifiant supérieur (lecture incrémentale)
     *
     * @return list<ConversationMessage> du plus ancien au plus récent
     */
    public function messagesOf(int $conversationId, int $afterId = 0): array;

    /** Remplace le texte d'un message : l'ancienne version est conservée (audit, jamais affichée) et la date de modification posée. */
    public function updateBody(int $messageId, string $body, \DateTimeImmutable $now): void;

    /**
     * Messages déjà reçus par le client (identifiant ≤ $upToMessageId) modifiés APRÈS $since, du plus ancien au plus récent
     * (polling : les autres participants voient le texte corrigé).
     *
     * @return list<ConversationMessage>
     */
    public function editedSince(int $conversationId, \DateTimeImmutable $since, int $upToMessageId): array;

    /**
     * Anciennes versions d'un message, la plus ancienne d'abord (audit).
     *
     * @return list<array{body: string, savedAt: \DateTimeImmutable}>
     */
    public function versionsOf(int $messageId): array;

    public function countMessagesBySince(int $authorId, \DateTimeImmutable $since): int;

    /** Modifications de messages faites par cet auteur depuis $since (limite de débit : une modification compte comme un envoi). */
    public function countEditsBySince(int $authorId, \DateTimeImmutable $since): int;
}
