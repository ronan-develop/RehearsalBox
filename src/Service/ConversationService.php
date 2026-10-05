<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\TransactionRunner;
use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Repository\Contract\ConversationMessageRepositoryInterface;
use App\Repository\Contract\ConversationPresenceRepositoryInterface;
use App\Repository\Contract\ConversationRepositoryInterface;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Security\ConversationInputPolicy;
use App\Security\Exception\AccessDeniedException;
use App\Service\Contract\ConversationMentionsInterface;
use App\Service\Contract\NewConversationNotifierInterface;
use App\Service\Exception\ConversationValidationException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Messagerie entre deux groupes, côté ÉCRITURE : démarrer, répondre, renommer. Accès : être participant (membre de l'un des
 * deux groupes ou invité, cf. ConversationAccess) ; un fil interdit et un fil inexistant produisent le même refus.
 * La lecture (listes, ouverture, polling) est dans ConversationReader ; corbeille et avis : ConversationTrashService ;
 * retrait d'un invité : ConversationGuestService.
 */
final class ConversationService
{
    public const MAX_MESSAGES_PER_HOUR = ConversationRateLimit::MAX_PER_HOUR;

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
        private readonly NewConversationNotifierInterface $notifier = new NoNewConversationNotice(),
        private readonly ConversationMentionsInterface $mentions = new NoConversationMentions(),
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
        $plan = $this->mentions->plan($userId, $initiatorGroupId, $targetGroupId, null, $body, $mentionIds);
        $now = $this->clock->now();
        $this->rateLimit->assertWithin($userId, $now);

        [$conversation, $first] = $this->transactions->run(function () use ($userId, $initiatorGroupId, $targetGroupId, $title, $body, $now, $plan): array {
            $conversation = $this->conversations->create($initiatorGroupId, $targetGroupId, $title, $now, $userId);
            $this->mentions->addGuests($plan, $userId, $conversation->id(), $now);
            $first = $this->messages->addMessage($conversation->id(), $userId, $body, $now);
            $this->mentions->record($plan, $first->id());
            $this->presence->markRead($conversation->id(), $userId, $now);

            return [$conversation, $first];
        });

        $this->mentions->notify($plan, $conversation, $userId, $first->authorName(), $now);

        // Après la validation de la transaction : le groupe visé est prévenu par e-mail (une fois, sans le contenu).
        // Un échec d'envoi ne remonte jamais : le message est déjà envoyé.
        $initiator = $this->groups->findById($initiatorGroupId);
        $target = $this->groups->findById($targetGroupId);
        if ($initiator !== null && $target !== null) {
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
        $plan = $this->mentions->plan($userId, $conversation->initiatorGroupId(), $conversation->targetGroupId(), $conversationId, $body, $mentionIds);
        $now = $this->clock->now();
        $this->rateLimit->assertWithin($userId, $now);

        $message = $this->transactions->run(function () use ($conversationId, $userId, $body, $now, $plan): ConversationMessage {
            $this->mentions->addGuests($plan, $userId, $conversationId, $now);
            $message = $this->messages->addMessage($conversationId, $userId, $body, $now);
            $this->mentions->record($plan, $message->id());
            // Répondre suppose d'avoir lu le fil : il n'est pas « non lu » pour son propre auteur.
            $this->presence->markRead($conversationId, $userId, $now);

            return $message;
        });

        // Après la validation de la transaction : les personnes taguées sont prévenues par e-mail (jamais le contenu).
        $this->mentions->notify($plan, $conversation, $userId, $message->authorName(), $now);

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

    private function participantConversation(int $userId, int $conversationId): Conversation
    {
        return $this->access->participant($userId, $conversationId);
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
