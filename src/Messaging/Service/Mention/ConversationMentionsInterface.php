<?php

declare(strict_types=1);

namespace App\Messaging\Service\Mention;

use App\Messaging\Entity\Conversation;
use App\Messaging\Entity\ConversationMessage;
use App\Messaging\Entity\MentionPlan;
use App\Messaging\Exception\ConversationValidationException;

/** Mentions d'une conversation (@Nom) : validation, invitation des extérieurs, enregistrement, e-mails et affichage. */
interface ConversationMentionsInterface
{
    /**
     * @param list<mixed> $userIds identifiants envoyés par le client (non fiables)
     *
     * @throws ConversationValidationException
     */
    public function plan(int $actorId, ?int $initiatorGroupId, ?int $targetGroupId, ?int $conversationId, string $body, array $userIds): MentionPlan;

    /**
     * @param list<mixed> $newIds
     *
     * @throws ConversationValidationException
     */
    public function planEdit(int $actorId, Conversation $conversation, int $messageId, string $body, array $newIds): MentionPlan;

    /** Remplace les mentions d'un message modifié (aucune mention restante = elles sont toutes retirées). */
    public function replace(MentionPlan $plan, int $messageId): void;

    /** Invite les extérieurs ; une ligne du fil l'annonce aux deux groupes. À appeler dans la transaction, avant le message. */
    public function addGuests(MentionPlan $plan, int $actorId, int $conversationId, \DateTimeImmutable $now): void;

    /** Prévient par e-mail les personnes mentionnées, APRÈS la validation de la transaction (un échec d'envoi ne remonte jamais). */
    public function notify(MentionPlan $plan, Conversation $conversation, int $authorId, string $authorName, \DateTimeImmutable $now): void;

    /** Enregistre les mentions du message (après son insertion). */
    public function record(MentionPlan $plan, int $messageId): void;

    /**
     * Mentions enregistrées pour ces messages (affichage du fil).
     *
     * @param list<ConversationMessage> $messages
     *
     * @return array<int, array<int, string>> identifiant du message => (identifiant de la personne => « @Nom »)
     */
    public function forMessages(array $messages): array;
}
