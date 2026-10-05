<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Entity\ConversationSummary;

interface ConversationRepositoryInterface
{
    public const BOX_ACTIVE = 'active';
    public const BOX_ARCHIVED = 'archived';

    public function create(int $initiatorGroupId, int $targetGroupId, ?string $title, \DateTimeImmutable $now, ?int $createdBy = null): Conversation;

    /** @param string|null $title null pour retirer le titre (la conversation reprend le label de ses deux groupes) */
    public function rename(int $conversationId, ?string $title): void;

    public function addMessage(int $conversationId, int $authorId, string $body, \DateTimeImmutable $now, bool $system = false): ConversationMessage;

    /** Un message précis de la conversation (ancre d'une lecture incrémentale), null s'il n'en fait pas partie. */
    public function messageById(int $conversationId, int $messageId): ?ConversationMessage;

    /** Dernier message ordinaire (hors lignes système) écrit par la personne dans la conversation. */
    public function lastMessageBy(int $conversationId, int $authorId): ?ConversationMessage;

    public function findById(int $id): ?Conversation;

    /**
     * @param int $afterId ne renvoie que les messages d'identifiant supérieur (lecture incrémentale)
     *
     * @return list<ConversationMessage> du plus ancien au plus récent
     */
    public function messagesOf(int $conversationId, int $afterId = 0): array;

    /**
     * Conversations visibles par la personne (membre de l'un des deux groupes). L'archivage est DÉRIVÉ :
     * une conversation dont le dernier message est antérieur à $inactiveBefore est archivée, sinon active.
     *
     * @return list<ConversationSummary> la plus récemment active d'abord
     */
    public function listFor(int $userId, string $box, \DateTimeImmutable $inactiveBefore): array;

    /** Date de dernière lecture de la personne, null si elle n'a jamais ouvert la conversation. */
    public function lastReadAt(int $conversationId, int $userId): ?\DateTimeImmutable;

    public function markRead(int $conversationId, int $userId, \DateTimeImmutable $now): void;

    /** @param string|null $box null = toutes les conversations (actives et archivées) */
    public function countUnreadFor(int $userId, \DateTimeImmutable $inactiveBefore, ?string $box = null): int;

    public function countMessagesBySince(int $authorId, \DateTimeImmutable $since): int;

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

    /** Nombre de personnes membres de l'un des deux groupes de la conversation. */
    public function participantCount(int $conversationId): int;

    /** Met la conversation à la corbeille : elle disparaît des listes et des compteurs des deux groupes. */
    public function moveToTrash(int $conversationId, \DateTimeImmutable $now): void;

    public function restore(int $conversationId): void;

    /** Suppression définitive (messages, lectures et avis d'envoi partent avec elle). */
    public function delete(int $conversationId): void;

    /**
     * Corbeille de la personne : ses conversations mises à la corbeille à partir de $trashedSince.
     *
     * @return list<ConversationSummary> la plus récemment supprimée d'abord
     */
    public function listTrashedBy(int $userId, \DateTimeImmutable $trashedSince): array;

    /** Supprime pour de bon les conversations à la corbeille avant $cutoff ; renvoie leur nombre. */
    public function purgeTrashedBefore(\DateTimeImmutable $cutoff): int;
}
