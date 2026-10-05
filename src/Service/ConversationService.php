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
use App\Repository\Contract\ConversationMessageRepositoryInterface;
use App\Repository\Contract\ConversationPresenceRepositoryInterface;
use App\Repository\Contract\ConversationRepositoryInterface;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Security\ConversationInputPolicy;
use App\Security\Exception\AccessDeniedException;
use App\Service\Exception\ConversationValidationException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Messagerie entre deux groupes. Accès : être membre de l'un des deux groupes de la conversation.
 * Un fil interdit et un fil inexistant produisent le même refus (aucun indice sur son existence).
 * Une conversation sans message depuis ARCHIVE_AFTER est archivée (état dérivé, jamais stocké).
 * Participants : membres des deux groupes et invités (ConversationAccess). Corbeille et avis : ConversationTrashService ;
 * retrait d'un invité : ConversationGuestService.
 */
final class ConversationService
{
    public const MAX_MESSAGES_PER_HOUR = ConversationRateLimit::MAX_PER_HOUR;
    public const ARCHIVE_AFTER = '-30 days';
    private const TYPING_WINDOW = '-5 seconds';

    private readonly ConversationAccess $access;
    private readonly ConversationRateLimit $rateLimit;

    public function __construct(
        private readonly ConversationRepositoryInterface $conversations,
        private readonly ConversationMessageRepositoryInterface $messages,
        private readonly ConversationPresenceRepositoryInterface $presence,
        private readonly GroupRepositoryInterface $groups,
        private readonly TransactionRunner $transactions,
        private readonly ClockInterface $clock,
        private readonly ConversationInputPolicy $inputPolicy = new ConversationInputPolicy(),
        private readonly ?ConversationNotifier $notifier = null,
        private readonly ?ConversationMentionService $mentions = null,
        ?ConversationAccess $access = null,
        ?ConversationRateLimit $rateLimit = null,
    ) {
        $this->access = $access ?? new ConversationAccess($conversations, $groups);
        $this->rateLimit = $rateLimit ?? new ConversationRateLimit($messages);
    }

    /**
     * @throws AccessDeniedException             pas membre du groupe émetteur, groupe visé inconnu ou identique
     * @throws ConversationValidationException   titre ou message invalide
     * @param list<mixed> $mentionIds personnes taguées dans le premier message (identifiants non fiables, validés ici)
     *
     * @throws Exception\ConversationRateLimitException    trop de messages envoyés dans l'heure
     */
    public function start(int $userId, int $initiatorGroupId, int $targetGroupId, string $body, ?string $title = null, array $mentionIds = []): Conversation
    {
        if ($initiatorGroupId === $targetGroupId
            || !$this->groups->isMember($initiatorGroupId, $userId)
            || $this->groups->findById($targetGroupId) === null) {
            throw new AccessDeniedException(ConversationAccess::DENIED);
        }

        $title = $this->normalizedTitle($title);
        $body = $this->inputPolicy->normalize($body);
        $this->assertValid($title, $body);
        $plan = $this->mentions?->plan($userId, $initiatorGroupId, $targetGroupId, null, $body, $mentionIds);
        $now = $this->clock->now();
        $this->rateLimit->assertWithin($userId, $now);

        [$conversation, $first] = $this->transactions->run(function () use ($userId, $initiatorGroupId, $targetGroupId, $title, $body, $now, $plan): array {
            $conversation = $this->conversations->create($initiatorGroupId, $targetGroupId, $title, $now, $userId);
            if ($plan !== null) {
                $this->mentions->addGuests($plan, $userId, $conversation->id(), $now);
            }
            $first = $this->messages->addMessage($conversation->id(), $userId, $body, $now);
            $plan !== null && $this->mentions->record($plan, $first->id());
            $this->presence->markRead($conversation->id(), $userId, $now);

            return [$conversation, $first];
        });

        if ($plan !== null) {
            $this->mentions->notify($plan, $conversation, $userId, $first->authorName(), $now);
        }

        // Après la validation de la transaction : le groupe visé est prévenu par e-mail (une fois, sans le contenu).
        // Un échec d'envoi ne remonte jamais : le message est déjà envoyé.
        $initiator = $this->groups->findById($initiatorGroupId);
        $target = $this->groups->findById($targetGroupId);
        if ($this->notifier !== null && $initiator !== null && $target !== null) {
            $this->notifier->newConversation($conversation, $first->authorName(), $initiator->name(), $target, $now);
        }

        return $conversation;
    }

    /**
     * @param list<mixed> $mentionIds personnes taguées (identifiants non fiables, validés ici) ; une personne extérieure
     *                                aux deux groupes est invitée à CETTE conversation
     *
     * @throws AccessDeniedException @throws ConversationValidationException @throws Exception\ConversationRateLimitException
     */
    public function reply(int $userId, int $conversationId, string $body, array $mentionIds = []): ConversationMessage
    {
        $conversation = $this->participantConversation($userId, $conversationId);

        $body = $this->inputPolicy->normalize($body);
        $this->assertValid(null, $body);
        $plan = $this->mentions?->plan($userId, $conversation->initiatorGroupId(), $conversation->targetGroupId(), $conversationId, $body, $mentionIds);
        $now = $this->clock->now();
        $this->rateLimit->assertWithin($userId, $now);

        $message = $this->transactions->run(function () use ($conversationId, $userId, $body, $now, $plan): ConversationMessage {
            if ($plan !== null) {
                $this->mentions->addGuests($plan, $userId, $conversationId, $now);
            }
            $message = $this->messages->addMessage($conversationId, $userId, $body, $now);
            $plan !== null && $this->mentions->record($plan, $message->id());
            // Répondre suppose d'avoir lu le fil : il n'est pas « non lu » pour son propre auteur.
            $this->presence->markRead($conversationId, $userId, $now);

            return $message;
        });

        // Après la validation de la transaction : les personnes taguées sont prévenues par e-mail (jamais le contenu).
        if ($plan !== null) {
            $this->mentions->notify($plan, $conversation, $userId, $message->authorName(), $now);
        }

        return $message;
    }

    /**
     * Change le titre (vide ou null : le retire). Tout membre peut renommer ; une ligne système l'indique dans le fil.
     * Rien ne se passe si le titre ne change pas.
     *
     * @throws AccessDeniedException @throws ConversationValidationException @throws Exception\ConversationRateLimitException
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
        $this->rateLimit->assertWithin($userId, $now);

        $line = $title === null ? 'a retiré le titre de la conversation' : "a renommé la conversation « {$title} »";
        $this->transactions->run(function () use ($conversationId, $userId, $title, $line, $now): void {
            $this->conversations->rename($conversationId, $title);
            $this->messages->addMessage($conversationId, $userId, $line, $now, true);
            $this->presence->markRead($conversationId, $userId, $now);
        });
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
        $this->participantConversation($userId, $conversationId);
        $messages = $this->messages->editedSince($conversationId, $since, $upToMessageId);

        return ['messages' => $messages, 'mentions' => $this->mentions?->forMessages($messages) ?? []];
    }

    /** Ouvre le fil complet et le marque lu pour cette personne seulement. @throws AccessDeniedException */
    public function open(int $userId, int $conversationId): ConversationThread
    {
        $conversation = $this->participantConversation($userId, $conversationId);
        $lastRead = $this->presence->lastReadAt($conversationId, $userId);
        $this->presence->markRead($conversationId, $userId, $this->clock->now());

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
                $this->presence->markRead($conversationId, $userId, $this->clock->now());
                break;
            }
        }

        return $thread;
    }

    /** « En train d'écrire » : au plus un signal pris en compte toutes les 2 secondes (le dépôt l'applique). @throws AccessDeniedException */
    public function typing(int $userId, int $conversationId): void
    {
        $this->participantConversation($userId, $conversationId);
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

    private function thread(Conversation $conversation, int $userId, int $afterId, ?\DateTimeImmutable $lastRead = null): ConversationThread
    {
        $messages = $this->messages->messagesOf($conversation->id(), $afterId);
        $firstUnreadId = $afterId === 0 ? $this->firstUnreadId($messages, $userId, $lastRead) : null;

        return new ConversationThread(
            $conversation,
            $this->labelOf($conversation),
            $messages,
            $this->presence->typingNames($conversation->id(), $userId, $this->clock->now()->modify(self::TYPING_WINDOW)),
            $this->seenReceipt($conversation, $userId),
            $this->authorGroups($conversation, $messages),
            $firstUnreadId,
            $afterId > 0 ? $this->messages->messageById($conversation->id(), $afterId) : null,
            $this->mentions?->forMessages($messages) ?? [],
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
        $mine = $this->messages->lastMessageBy($conversation->id(), $userId);
        if ($mine === null) {
            return null;
        }

        return new SeenReceipt(
            $mine->id(),
            $this->presence->readersOf($conversation->id(), $mine->createdAt(), $userId),
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

    private function participantConversation(int $userId, int $conversationId): Conversation
    {
        return $this->access->participant($userId, $conversationId);
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
}
