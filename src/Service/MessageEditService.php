<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\TransactionRunner;
use App\Entity\ConversationMessage;
use App\Repository\Contract\ConversationMessageRepositoryInterface;
use App\Security\ConversationInputPolicy;
use App\Security\Exception\AccessDeniedException;
use App\Service\Exception\ConversationRateLimitException;
use App\Service\Exception\ConversationValidationException;
use Symfony\Component\Clock\ClockInterface;
use App\Service\Contract\ConversationMentionsInterface;

/**
 * Éditer son propre message (#200). Seul l'AUTEUR modifie son message (même pas l'initiateur de la conversation ni un
 * membre de son groupe), pendant EDIT_WINDOW après l'envoi, jamais une ligne système : tout autre cas produit le refus
 * uniforme. Mêmes règles de saisie qu'à l'envoi, une modification compte dans la limite horaire, l'ancienne version est
 * conservée (audit, jamais affichée). Les mentions suivent le texte ; une modification n'envoie JAMAIS d'e-mail.
 */
final class MessageEditService
{
    public const EDIT_WINDOW = '-15 minutes';

    private readonly ConversationRateLimit $rateLimit;

    public function __construct(
        private readonly ConversationAccess $access,
        private readonly ConversationMessageRepositoryInterface $messages,
        private readonly ConversationMentionsInterface $mentions,
        private readonly TransactionRunner $transactions,
        private readonly ClockInterface $clock,
        private readonly ConversationInputPolicy $inputPolicy = new ConversationInputPolicy(),
        ?ConversationRateLimit $rateLimit = null,
    ) {
        $this->rateLimit = $rateLimit ?? new ConversationRateLimit($messages);
    }

    /**
     * @param list<mixed> $mentionIds nouvelles personnes taguées (identifiants non fiables, validés comme à l'envoi)
     *
     * @throws AccessDeniedException                pas l'auteur, ligne système, message ou conversation inconnus
     * @throws ConversationValidationException      délai dépassé, texte invalide, mention invalide
     * @throws ConversationRateLimitException       trop d'écritures dans l'heure
     */
    public function edit(int $userId, int $conversationId, int $messageId, string $body, array $mentionIds = []): ConversationMessage
    {
        $conversation = $this->access->participant($userId, $conversationId);
        $message = $this->messages->messageById($conversationId, $messageId);
        if ($message === null || $message->isSystem() || $message->authorId() !== $userId) {
            throw new AccessDeniedException(ConversationAccess::DENIED);
        }

        $now = $this->clock->now();
        if ($message->createdAt() < $now->modify(self::EDIT_WINDOW)) {
            throw new ConversationValidationException(['message' => 'Ce message ne peut plus être modifié (15 minutes après son envoi).']);
        }
        $body = $this->inputPolicy->normalize($body);
        if (($violation = $this->inputPolicy->bodyViolation($body)) !== null) {
            throw new ConversationValidationException(['message' => $violation]);
        }
        if ($body === $message->body()) {
            return $message;
        }
        $this->rateLimit->assertWithin($userId, $now);
        $plan = $this->mentions->planEdit($userId, $conversation, $messageId, $body, $mentionIds);

        $this->transactions->run(function () use ($plan, $userId, $conversationId, $messageId, $body, $now): void {
            $this->mentions->addGuests($plan, $userId, $conversationId, $now);
            $this->messages->updateBody($messageId, $body, $now);
            $this->mentions->replace($plan, $messageId);
        });

        return $this->messages->messageById($conversationId, $messageId) ?? $message;
    }
}
