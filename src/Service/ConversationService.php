<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\TransactionRunner;
use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Entity\ConversationSummary;
use App\Entity\ConversationThread;
use App\Entity\Group;
use App\Entity\SeenReceipt;
use App\Entity\ConversationAlert;
use App\Repository\Contract\ConversationAlertRepositoryInterface;
use App\Repository\Contract\ConversationRepositoryInterface;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Security\ConversationInputPolicy;
use App\Security\Exception\AccessDeniedException;
use App\Service\Exception\ConversationRateLimitException;
use App\Service\Exception\ConversationValidationException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Messagerie entre deux groupes. Accès : être membre de l'un des deux groupes de la conversation.
 * Un fil interdit et un fil inexistant produisent le même refus (aucun indice sur son existence).
 * Une conversation sans message depuis ARCHIVE_AFTER est archivée (état dérivé, jamais stocké).
 * Seule la personne qui l'a ouverte peut la mettre à la corbeille (TRASH_RETENTION pour la restaurer) ; les autres
 * participants en sont prévenus par un avis dans l'application.
 */
final class ConversationService
{
    public const MAX_MESSAGES_PER_HOUR = 30;
    public const ARCHIVE_AFTER = '-30 days';
    public const TRASH_RETENTION = '-30 days';
    private const TYPING_WINDOW = '-5 seconds';
    private const DENIED = 'Accès refusé.';

    public function __construct(
        private readonly ConversationRepositoryInterface $conversations,
        private readonly GroupRepositoryInterface $groups,
        private readonly TransactionRunner $transactions,
        private readonly ClockInterface $clock,
        private readonly ConversationInputPolicy $inputPolicy = new ConversationInputPolicy(),
        private readonly ?ConversationNotifier $notifier = null,
        private readonly ?ConversationAlertRepositoryInterface $alerts = null,
    ) {
    }

    /**
     * @throws AccessDeniedException             pas membre du groupe émetteur, groupe visé inconnu ou identique
     * @throws ConversationValidationException   titre ou message invalide
     * @throws ConversationRateLimitException    trop de messages envoyés dans l'heure
     */
    public function start(int $userId, int $initiatorGroupId, int $targetGroupId, string $body, ?string $title = null): Conversation
    {
        if ($initiatorGroupId === $targetGroupId
            || !$this->groups->isMember($initiatorGroupId, $userId)
            || $this->groups->findById($targetGroupId) === null) {
            throw new AccessDeniedException(self::DENIED);
        }

        $title = $this->normalizedTitle($title);
        $body = $this->inputPolicy->normalize($body);
        $this->assertValid($title, $body);
        $now = $this->clock->now();
        $this->assertWithinRateLimit($userId, $now);

        [$conversation, $first] = $this->transactions->run(function () use ($userId, $initiatorGroupId, $targetGroupId, $title, $body, $now): array {
            $conversation = $this->conversations->create($initiatorGroupId, $targetGroupId, $title, $now, $userId);
            $first = $this->conversations->addMessage($conversation->id(), $userId, $body, $now);
            $this->conversations->markRead($conversation->id(), $userId, $now);

            return [$conversation, $first];
        });

        // Après la validation de la transaction : le groupe visé est prévenu par e-mail (une fois, sans le contenu).
        // Un échec d'envoi ne remonte jamais : le message est déjà envoyé.
        $initiator = $this->groups->findById($initiatorGroupId);
        $target = $this->groups->findById($targetGroupId);
        if ($this->notifier !== null && $initiator !== null && $target !== null) {
            $this->notifier->newConversation($conversation, $first->authorName(), $initiator->name(), $target, $now);
        }

        return $conversation;
    }

    /** @throws AccessDeniedException @throws ConversationValidationException @throws ConversationRateLimitException */
    public function reply(int $userId, int $conversationId, string $body): ConversationMessage
    {
        $this->participantConversation($userId, $conversationId);

        $body = $this->inputPolicy->normalize($body);
        $this->assertValid(null, $body);
        $now = $this->clock->now();
        $this->assertWithinRateLimit($userId, $now);

        $message = $this->conversations->addMessage($conversationId, $userId, $body, $now);
        // Répondre suppose d'avoir lu le fil : il n'est pas « non lu » pour son propre auteur.
        $this->conversations->markRead($conversationId, $userId, $now);

        return $message;
    }

    /**
     * Change le titre (vide ou null : le retire). Tout membre peut renommer ; une ligne système l'indique dans le fil.
     * Rien ne se passe si le titre ne change pas.
     *
     * @throws AccessDeniedException @throws ConversationValidationException @throws ConversationRateLimitException
     */
    public function rename(int $userId, int $conversationId, ?string $title): void
    {
        $conversation = $this->participantConversation($userId, $conversationId);

        $title = $this->normalizedTitle($title);
        $this->assertValid($title, null);
        if ($title === $conversation->title()) {
            return;
        }
        $now = $this->clock->now();
        $this->assertWithinRateLimit($userId, $now);

        $line = $title === null ? 'a retiré le titre de la conversation' : "a renommé la conversation « {$title} »";
        $this->transactions->run(function () use ($conversationId, $userId, $title, $line, $now): void {
            $this->conversations->rename($conversationId, $title);
            $this->conversations->addMessage($conversationId, $userId, $line, $now, true);
            $this->conversations->markRead($conversationId, $userId, $now);
        });
    }

    /** Ouvre le fil complet et le marque lu pour cette personne seulement. @throws AccessDeniedException */
    public function open(int $userId, int $conversationId): ConversationThread
    {
        $conversation = $this->participantConversation($userId, $conversationId);
        $lastRead = $this->conversations->lastReadAt($conversationId, $userId);
        $this->conversations->markRead($conversationId, $userId, $this->clock->now());

        return $this->thread($conversation, $userId, 0, $lastRead);
    }

    /**
     * Lecture incrémentale (polling) : seulement les messages après $afterId, avec qui écrit et « vu par ».
     * Recevoir en direct un message des autres vaut lecture.
     *
     * @throws AccessDeniedException
     */
    public function poll(int $userId, int $conversationId, int $afterId): ConversationThread
    {
        $conversation = $this->participantConversation($userId, $conversationId);
        $thread = $this->thread($conversation, $userId, $afterId);

        foreach ($thread->messages() as $message) {
            if ($message->authorId() !== $userId) {
                $this->conversations->markRead($conversationId, $userId, $this->clock->now());
                break;
            }
        }

        return $thread;
    }

    /** « En train d'écrire » : au plus un signal pris en compte toutes les 2 secondes (le dépôt l'applique). @throws AccessDeniedException */
    public function typing(int $userId, int $conversationId): void
    {
        $this->participantConversation($userId, $conversationId);
        $this->conversations->setTyping($conversationId, $userId, $this->clock->now());
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

    private function thread(Conversation $conversation, int $userId, int $afterId, ?\DateTimeImmutable $lastRead = null): ConversationThread
    {
        $messages = $this->conversations->messagesOf($conversation->id(), $afterId);
        $firstUnreadId = $afterId === 0 ? $this->firstUnreadId($messages, $userId, $lastRead) : null;

        return new ConversationThread(
            $conversation,
            $this->labelOf($conversation),
            $messages,
            $this->conversations->typingNames($conversation->id(), $userId, $this->clock->now()->modify(self::TYPING_WINDOW)),
            $this->seenReceipt($conversation, $userId),
            $this->authorGroups($conversation, $messages),
            $firstUnreadId,
            $afterId > 0 ? $this->conversations->messageById($conversation->id(), $afterId) : null,
        );
    }

    /** @param list<ConversationMessage> $messages */
    private function firstUnreadId(array $messages, int $userId, ?\DateTimeImmutable $lastRead): ?int
    {
        foreach ($messages as $message) {
            if ($message->authorId() !== $userId && ($lastRead === null || $message->createdAt() > $lastRead)) {
                return $message->id();
            }
        }

        return null;
    }

    private function seenReceipt(Conversation $conversation, int $userId): ?SeenReceipt
    {
        $mine = $this->conversations->lastMessageBy($conversation->id(), $userId);
        if ($mine === null) {
            return null;
        }

        return new SeenReceipt(
            $mine->id(),
            $this->conversations->readersOf($conversation->id(), $mine->createdAt(), $userId),
            max(0, $this->conversations->participantCount($conversation->id()) - 1),
        );
    }

    /**
     * Pastille : groupe d'appartenance de chaque auteur parmi les deux groupes de la conversation ;
     * null s'il est dans les deux (ou plus dans aucun) : on ne devine pas.
     *
     * @param list<ConversationMessage> $messages
     *
     * @return array<int, Group|null>
     */
    private function authorGroups(Conversation $conversation, array $messages): array
    {
        $initiator = $this->groups->findById($conversation->initiatorGroupId());
        $target = $this->groups->findById($conversation->targetGroupId());

        $result = [];
        foreach ($messages as $message) {
            $authorId = $message->authorId();
            if (array_key_exists($authorId, $result)) {
                continue;
            }
            $inInitiator = $initiator !== null && $this->groups->isMember($initiator->id(), $authorId);
            $inTarget = $target !== null && $this->groups->isMember($target->id(), $authorId);
            $result[$authorId] = $inInitiator === $inTarget ? null : ($inInitiator ? $initiator : $target);
        }

        return $result;
    }

    /**
     * Met la conversation à la corbeille : elle disparaît chez les deux groupes, les autres participants en sont prévenus.
     *
     * @throws AccessDeniedException pas l'initiateur, conversation inconnue ou déjà à la corbeille
     */
    public function delete(int $userId, int $conversationId): void
    {
        $conversation = $this->ownedConversation($userId, $conversationId);
        if ($conversation->deletedAt() !== null) {
            throw new AccessDeniedException(self::DENIED);
        }
        $now = $this->clock->now();
        $this->transactions->run(function () use ($conversationId, $userId, $now): void {
            $this->conversations->moveToTrash($conversationId, $now);
            $this->alerts?->notifyParticipants($conversationId, $userId, ConversationAlert::DELETED, $now);
        });
    }

    /** @throws AccessDeniedException pas l'initiateur, pas à la corbeille, ou corbeille expirée */
    public function restore(int $userId, int $conversationId): void
    {
        $conversation = $this->trashedConversation($userId, $conversationId);
        $now = $this->clock->now();
        $this->transactions->run(function () use ($conversation, $userId, $now): void {
            $this->conversations->restore($conversation->id());
            $this->alerts?->notifyParticipants($conversation->id(), $userId, ConversationAlert::RESTORED, $now);
        });
    }

    /** Suppression définitive d'une conversation déjà à la corbeille. @throws AccessDeniedException */
    public function deletePermanently(int $userId, int $conversationId): void
    {
        $this->conversations->delete($this->trashedConversation($userId, $conversationId)->id());
    }

    /** Corbeille de la personne ; les conversations expirées sont purgées au passage (aucune tâche planifiée nécessaire). @return list<ConversationSummary> */
    public function trash(int $userId): array
    {
        $cutoff = $this->trashCutoff();
        $this->conversations->purgeTrashedBefore($cutoff);

        return $this->conversations->listTrashedBy($userId, $cutoff);
    }

    /** @return list<ConversationAlert> avis non fermés des 30 derniers jours */
    public function alertsFor(int $userId): array
    {
        return $this->alerts?->findActiveFor($userId, $this->trashCutoff()) ?? [];
    }

    public function alertCount(int $userId): int
    {
        return $this->alerts?->countActiveFor($userId, $this->trashCutoff()) ?? 0;
    }

    public function dismissAlert(int $userId, int $alertId): void
    {
        $this->alerts?->dismiss($alertId, $userId, $this->clock->now());
    }

    private function trashCutoff(): \DateTimeImmutable
    {
        return $this->clock->now()->modify(self::TRASH_RETENTION);
    }

    /** Conversation ouverte par cette personne (en service ou à la corbeille). @throws AccessDeniedException */
    private function ownedConversation(int $userId, int $conversationId): Conversation
    {
        $conversation = $this->conversations->findById($conversationId);
        if ($conversation === null || $conversation->createdBy() !== $userId) {
            throw new AccessDeniedException(self::DENIED);
        }

        return $conversation;
    }

    /** Conversation de cette personne à la corbeille depuis moins de TRASH_RETENTION. @throws AccessDeniedException */
    private function trashedConversation(int $userId, int $conversationId): Conversation
    {
        $conversation = $this->ownedConversation($userId, $conversationId);
        if ($conversation->deletedAt() === null || $conversation->deletedAt() < $this->trashCutoff()) {
            throw new AccessDeniedException(self::DENIED);
        }

        return $conversation;
    }

    private function participantConversation(int $userId, int $conversationId): Conversation
    {
        $conversation = $this->conversations->findById($conversationId);
        if ($conversation === null
            || $conversation->deletedAt() !== null
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

    private function normalizedTitle(?string $title): ?string
    {
        $title = $title === null ? '' : $this->inputPolicy->normalize($title);

        return $title === '' ? null : $title;
    }

    /** @param string|null $body null quand seul le titre est contrôlé (renommage) */
    private function assertValid(?string $title, ?string $body): void
    {
        $errors = [];
        if ($title !== null && ($violation = $this->inputPolicy->titleViolation($title)) !== null) {
            $errors['title'] = $violation;
        }
        if ($body !== null && ($violation = $this->inputPolicy->bodyViolation($body)) !== null) {
            $errors['message'] = $violation;
        }

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
