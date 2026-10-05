<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ConversationMessage;
use App\Entity\ConversationSummary;
use App\Entity\ConversationThread;
use App\Repository\Contract\ConversationMessageRepositoryInterface;
use App\Repository\Contract\ConversationPresenceRepositoryInterface;
use App\Repository\Contract\ConversationRepositoryInterface;
use App\Security\Exception\AccessDeniedException;
use App\Service\Contract\ConversationMentionsInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Lecture de la messagerie : listes, ouverture d'un fil, polling, corrections vues par les autres, « en train d'écrire ».
 * Accès : être participant (ConversationAccess) ; un fil interdit et un fil inexistant produisent le même refus.
 * Une conversation sans message depuis ARCHIVE_AFTER est archivée (état dérivé, jamais stocké). L'écriture est dans
 * ConversationService.
 */
final class ConversationReader
{
    public const ARCHIVE_AFTER = '-30 days';

    public function __construct(
        private readonly ConversationAccess $access,
        private readonly ConversationThreadBuilder $threads,
        private readonly ConversationRepositoryInterface $conversations,
        private readonly ConversationMessageRepositoryInterface $messages,
        private readonly ConversationPresenceRepositoryInterface $presence,
        private readonly ClockInterface $clock,
        private readonly ConversationMentionsInterface $mentions = new NoConversationMentions(),
    ) {
    }

    /** Ouvre le fil complet et le marque lu pour cette personne seulement. @throws AccessDeniedException */
    public function open(int $userId, int $conversationId): ConversationThread
    {
        $conversation = $this->access->participant($userId, $conversationId);
        $lastRead = $this->presence->lastReadAt($conversationId, $userId);
        $this->presence->markRead($conversationId, $userId, $this->clock->now());

        return $this->threads->build($conversation, $userId, 0, $lastRead);
    }

    /**
     * Lecture incrémentale (polling) : seulement les messages après $afterId, avec qui écrit et « vu par ».
     * Recevoir en direct un message des autres vaut lecture.
     *
     * @throws AccessDeniedException
     */
    public function poll(int $userId, int $conversationId, int $afterId): ConversationThread
    {
        $conversation = $this->access->participant($userId, $conversationId);
        $thread = $this->threads->build($conversation, $userId, $afterId);

        foreach ($thread->messages() as $message) {
            if ($message->authorId() !== $userId) {
                $this->presence->markRead($conversationId, $userId, $this->clock->now());
                break;
            }
        }

        return $thread;
    }

    /**
     * Messages déjà reçus par le client (identifiant ≤ $upToMessageId) corrigés APRÈS $since, avec leurs mentions : le
     * polling les renvoie pour que les autres participants voient le texte corrigé (#200).
     *
     * @return array{messages: list<ConversationMessage>, mentions: array<int, array<int, string>>}
     *
     * @throws AccessDeniedException
     */
    public function edited(int $userId, int $conversationId, \DateTimeImmutable $since, int $upToMessageId): array
    {
        $this->access->participant($userId, $conversationId);
        $messages = $this->messages->editedSince($conversationId, $since, $upToMessageId);

        return ['messages' => $messages, 'mentions' => $this->mentions->forMessages($messages)];
    }

    /** « En train d'écrire » : au plus un signal pris en compte toutes les 2 secondes (le dépôt l'applique). @throws AccessDeniedException */
    public function typing(int $userId, int $conversationId): void
    {
        $this->access->participant($userId, $conversationId);
        $this->presence->setTyping($conversationId, $userId, $this->clock->now());
    }

    /** @return list<ConversationSummary> */
    public function listFor(int $userId, string $box): array
    {
        return $this->conversations->listFor($userId, $box, $this->archiveCutoff());
    }

    /** @param string|null $box null = actives et archivées */
    public function unreadCount(int $userId, ?string $box = null): int
    {
        return $this->conversations->countUnreadFor($userId, $this->archiveCutoff(), $box);
    }

    private function archiveCutoff(): \DateTimeImmutable
    {
        return $this->clock->now()->modify(self::ARCHIVE_AFTER);
    }
}
