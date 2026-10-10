<?php

declare(strict_types=1);

namespace App\Messaging\Service\Direct;

use App\Account\Repository\UserRepositoryInterface;
use App\Database\TransactionRunner;
use App\Messaging\Entity\Conversation;
use App\Messaging\Notification\DirectMessageNotifier;
use App\Messaging\Exception\ConversationRateLimitException;
use App\Messaging\Exception\ConversationValidationException;
use App\Messaging\Repository\ConversationMessageRepositoryInterface;
use App\Messaging\Repository\ConversationPresenceRepositoryInterface;
use App\Messaging\Repository\ConversationRepositoryInterface;
use App\Messaging\Service\ConversationInputPolicy;
use App\Messaging\Service\ConversationRateLimit;
use App\Security\Exception\AccessDeniedException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Message direct entre deux personnes (#269), côté démarrage : ouvre la conversation de la paire (ou rouvre celle qui existe,
 * jamais de doublon) et y écrit le message. Répondre, éditer, citer, supprimer passent ensuite par les services de la
 * messagerie, la règle d'accès (ConversationAccess) étant la même pour tous.
 */
final class DirectConversationService
{
    private readonly DirectMessagePolicy $policy;
    private readonly ConversationRateLimit $rateLimit;

    public function __construct(
        private readonly ConversationRepositoryInterface $conversations,
        private readonly ConversationMessageRepositoryInterface $messages,
        private readonly ConversationPresenceRepositoryInterface $presence,
        UserRepositoryInterface $users,
        private readonly TransactionRunner $transactions,
        private readonly ClockInterface $clock,
        private readonly ConversationInputPolicy $inputPolicy = new ConversationInputPolicy(),
        private readonly ?DirectMessageNotifier $notifier = null,
    ) {
        $this->policy = new DirectMessagePolicy($users);
        $this->rateLimit = new ConversationRateLimit($messages);
    }

    /**
     * @throws AccessDeniedException           soi-même, personne inconnue ou inactive
     * @throws ConversationValidationException message invalide
     * @throws ConversationRateLimitException  trop de messages envoyés dans l'heure
     */
    public function start(int $userId, int $otherUserId, string $body): Conversation
    {
        $this->policy->assertCanWrite($userId, $otherUserId);

        $body = $this->inputPolicy->normalize($body);
        if (($violation = $this->inputPolicy->bodyViolation($body)) !== null) {
            throw new ConversationValidationException(['message' => $violation]);
        }
        $now = $this->clock->now();

        [$conversation, $first] = $this->transactions->run(function () use ($userId, $otherUserId, $body, $now): array {
            $this->rateLimit->assertWithin($userId, $now);
            $conversation = $this->conversations->openDirect($userId, $otherUserId, $now);
            $first = $this->messages->addMessage($conversation->id(), $userId, $body, $now);
            $this->presence->markRead($conversation->id(), $userId, $now);

            return [$conversation, $first];
        });

        // Après la validation de la transaction : l'autre personne est prévenue par e-mail (sans le texte), un échec ne remonte jamais.
        $this->notifier?->newMessage($conversation, $userId, $first->authorName(), $now);

        return $conversation;
    }
}
