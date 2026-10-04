<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\TransactionRunner;
use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Entity\ConversationSummary;
use App\Entity\ConversationThread;
use App\Repository\Contract\ConversationRepositoryInterface;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Security\ConversationInputPolicy;
use App\Security\Exception\AccessDeniedException;
use App\Service\Exception\ConversationRateLimitException;
use App\Service\Exception\ConversationValidationException;

/**
 * Messagerie entre deux groupes. Accès : être membre de l'un des deux groupes de la conversation.
 * Un fil interdit et un fil inexistant produisent le même refus (aucun indice sur son existence).
 */
final class ConversationService
{
    public const MAX_MESSAGES_PER_HOUR = 30;

    private const DENIED = 'Accès refusé.';

    public function __construct(
        private readonly ConversationRepositoryInterface $conversations,
        private readonly GroupRepositoryInterface $groups,
        private readonly TransactionRunner $transactions,
        private readonly ConversationInputPolicy $inputPolicy = new ConversationInputPolicy(),
    ) {
    }

    /**
     * @throws AccessDeniedException             pas membre du groupe émetteur, groupe visé inconnu ou identique
     * @throws ConversationValidationException   sujet ou message invalide
     * @throws ConversationRateLimitException    trop de messages envoyés dans l'heure
     */
    public function start(int $userId, int $initiatorGroupId, int $targetGroupId, string $subject, string $body, ?\DateTimeImmutable $now = null): Conversation
    {
        $now ??= new \DateTimeImmutable();

        if ($initiatorGroupId === $targetGroupId
            || !$this->groups->isMember($initiatorGroupId, $userId)
            || $this->groups->findById($targetGroupId) === null) {
            throw new AccessDeniedException(self::DENIED);
        }

        $subject = $this->inputPolicy->normalize($subject);
        $body = $this->inputPolicy->normalize($body);
        $this->assertValid($subject, $body);
        $this->assertWithinRateLimit($userId, $now);

        return $this->transactions->run(function () use ($userId, $initiatorGroupId, $targetGroupId, $subject, $body, $now): Conversation {
            $conversation = $this->conversations->create($initiatorGroupId, $targetGroupId, $subject, $now);
            $this->conversations->addMessage($conversation->id(), $userId, $body, $now);

            return $conversation;
        });
    }

    /** @throws AccessDeniedException @throws ConversationValidationException @throws ConversationRateLimitException */
    public function reply(int $userId, int $conversationId, string $body, ?\DateTimeImmutable $now = null): ConversationMessage
    {
        $now ??= new \DateTimeImmutable();
        $this->participantConversation($userId, $conversationId);

        $body = $this->inputPolicy->normalize($body);
        $this->assertValid(null, $body);
        $this->assertWithinRateLimit($userId, $now);

        $message = $this->conversations->addMessage($conversationId, $userId, $body, $now);
        // Répondre suppose d'avoir lu le fil : il n'est pas « non lu » pour son propre auteur.
        $this->conversations->markRead($conversationId, $userId, $now);

        return $message;
    }

    /** Lit le fil et le marque lu pour cette personne seulement. @throws AccessDeniedException */
    public function open(int $userId, int $conversationId, ?\DateTimeImmutable $now = null): ConversationThread
    {
        $conversation = $this->participantConversation($userId, $conversationId);
        $this->conversations->markRead($conversationId, $userId, $now ?? new \DateTimeImmutable());

        return new ConversationThread($conversation, $this->labelOf($conversation), $this->conversations->messagesOf($conversationId));
    }

    /** @throws AccessDeniedException */
    public function archive(int $userId, int $conversationId, bool $archived): void
    {
        $this->participantConversation($userId, $conversationId);
        $this->conversations->setArchived($conversationId, $userId, $archived);
    }

    /** @return list<ConversationSummary> */
    public function listFor(int $userId, string $box): array
    {
        return $this->conversations->listFor($userId, $box);
    }

    public function unreadCount(int $userId): int
    {
        return $this->conversations->countUnreadFor($userId);
    }

    private function participantConversation(int $userId, int $conversationId): Conversation
    {
        $conversation = $this->conversations->findById($conversationId);
        if ($conversation === null
            || (!$this->groups->isMember($conversation->initiatorGroupId(), $userId)
                && !$this->groups->isMember($conversation->targetGroupId(), $userId))) {
            throw new AccessDeniedException(self::DENIED);
        }

        return $conversation;
    }

    private function labelOf(Conversation $conversation): string
    {
        $initiator = $this->groups->findById($conversation->initiatorGroupId());
        $target = $this->groups->findById($conversation->targetGroupId());

        return ($initiator?->name() ?? '?') . ' ↔ ' . ($target?->name() ?? '?');
    }

    private function assertValid(?string $subject, string $body): void
    {
        $errors = $this->inputPolicy->violations($subject, $body);
        if ($errors !== []) {
            throw new ConversationValidationException($errors);
        }
    }

    private function assertWithinRateLimit(int $userId, \DateTimeImmutable $now): void
    {
        if ($this->conversations->countMessagesBySince($userId, $now->modify('-1 hour')) >= self::MAX_MESSAGES_PER_HOUR) {
            throw new ConversationRateLimitException('Trop de messages envoyés : réessayez dans un moment.');
        }
    }
}
