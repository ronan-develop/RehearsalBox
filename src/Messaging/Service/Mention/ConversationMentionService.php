<?php

declare(strict_types=1);

namespace App\Messaging\Service\Mention;

use App\Messaging\Entity\Conversation;
use App\Messaging\Entity\ConversationMessage;
use App\Messaging\Entity\MentionPlan;
use App\Messaging\Repository\Participation\ConversationGuestRepositoryInterface;
use App\Messaging\Repository\Mention\ConversationMentionRepositoryInterface;
use App\Messaging\Repository\ConversationMessageRepositoryInterface;
use App\Group\Repository\GroupRepositoryInterface;
use App\Account\Repository\UserRepositoryInterface;
use App\Messaging\Exception\ConversationValidationException;
use App\Support\StrictId;
use App\Messaging\Service\Mention\ConversationMentionsInterface;
use App\Messaging\Notification\MentionNotifier;

/**
 * Mentions d'un message (#178) : valide les personnes désignées par IDENTIFIANT (jamais par le texte), sépare les
 * participants des extérieurs et invite ces derniers. Une personne n'est prise en compte que si « @Nom » figure dans le
 * texte. Seuls les membres de l'un des deux groupes peuvent faire entrer quelqu'un ; un invité mentionne les participants.
 */
final class ConversationMentionService implements ConversationMentionsInterface
{
    public const MAX_MENTIONS = 10;

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly GroupRepositoryInterface $groups,
        private readonly ConversationGuestRepositoryInterface $guests,
        private readonly ConversationMentionRepositoryInterface $mentions,
        private readonly ConversationMessageRepositoryInterface $messages,
        private readonly ?MentionNotifier $notifier = null,
    ) {
    }

    /**
     * @param list<mixed> $userIds identifiants envoyés par le client (non fiables)
     *
     * @throws ConversationValidationException identifiant invalide, personne inconnue ou inactive, trop de mentions, ajout interdit
     */
    public function plan(int $actorId, int $initiatorGroupId, int $targetGroupId, ?int $conversationId, string $body, array $userIds): MentionPlan
    {
        if ($userIds === []) {
            return new MentionPlan([], []);
        }
        $ids = [];
        foreach ($userIds as $value) {
            $ids[] = StrictId::from($value) ?? throw $this->refusal('Une personne mentionnée est invalide.');
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) > self::MAX_MENTIONS) {
            throw $this->refusal(sprintf('Vous pouvez mentionner au plus %d personnes par message.', self::MAX_MENTIONS));
        }

        $actorIsGroupMember = $this->groups->isMember($initiatorGroupId, $actorId) || $this->groups->isMember($targetGroupId, $actorId);
        $labels = [];
        $outsiders = [];
        foreach ($ids as $id) {
            if ($id === $actorId) {
                continue;
            }
            $user = $this->users->findById($id);
            if ($user === null || !$user->isActive()) {
                throw $this->refusal('Cette personne est introuvable.');
            }
            $label = '@' . $user->displayName();
            if (!str_contains($body, $label)) {
                continue;
            }
            $labels[$id] = $label;
            if (!$this->participates($id, $initiatorGroupId, $targetGroupId, $conversationId)) {
                $outsiders[$id] = $user->displayName();
            }
        }

        if ($outsiders !== [] && !$actorIsGroupMember) {
            throw $this->refusal("Seuls les membres des deux groupes peuvent ajouter quelqu'un à la conversation.");
        }
        ksort($labels);
        ksort($outsiders);

        return new MentionPlan($labels, $outsiders);
    }

    /**
     * Mentions d'un message MODIFIÉ (#200) : les personnes déjà mentionnées le restent tant que leur « @Nom » figure dans le
     * nouveau texte (le client n'a pas à renvoyer leur identifiant), celles dont le libellé a disparu sont retirées, et les
     * nouvelles ($newIds, validées comme à l'envoi) s'ajoutent. Seules les nouvelles peuvent faire entrer un extérieur.
     *
     * @param list<mixed> $newIds
     *
     * @throws ConversationValidationException
     */
    public function planEdit(int $actorId, Conversation $conversation, int $messageId, string $body, array $newIds): MentionPlan
    {
        $fresh = $this->plan($actorId, $conversation->initiatorGroupId(), $conversation->targetGroupId(), $conversation->id(), $body, $newIds);

        $labels = $fresh->labels();
        foreach ($this->mentions->forMessages([$messageId])[$messageId] ?? [] as $userId => $label) {
            if (!isset($labels[$userId]) && str_contains($body, $label)) {
                $labels[$userId] = $label;
            }
        }
        ksort($labels);

        return new MentionPlan($labels, $fresh->outsiders());
    }

    /** Remplace les mentions d'un message modifié (aucune mention restante = elles sont toutes retirées). */
    public function replace(MentionPlan $plan, int $messageId): void
    {
        $this->mentions->record($messageId, $plan->labels());
    }

    /** Invite les extérieurs ; une ligne du fil l'annonce aux deux groupes. À appeler dans la transaction, avant le message. */
    public function addGuests(MentionPlan $plan, int $actorId, int $conversationId, \DateTimeImmutable $now): void
    {
        foreach ($plan->outsiders() as $userId => $name) {
            if ($this->guests->add($conversationId, $userId, $actorId, $now)) {
                $this->messages->addMessage($conversationId, $actorId, "a ajouté {$name} à la conversation", $now, true);
            }
        }
    }

    /**
     * Prévient par e-mail les personnes mentionnées. À appeler APRÈS la validation de la transaction : le message est déjà
     * enregistré, un échec d'envoi ne remonte jamais (MentionNotifier).
     */
    public function notify(MentionPlan $plan, Conversation $conversation, int $authorId, string $authorName, \DateTimeImmutable $now): void
    {
        if (!$plan->isEmpty()) {
            $this->notifier?->mentioned($conversation, $authorId, $authorName, array_keys($plan->labels()), $now);
        }
    }

    /** Enregistre les mentions du message (après son insertion). */
    public function record(MentionPlan $plan, int $messageId): void
    {
        if (!$plan->isEmpty()) {
            $this->mentions->record($messageId, $plan->labels());
        }
    }

    /**
     * Mentions enregistrées pour ces messages (affichage du fil).
     *
     * @param list<ConversationMessage> $messages
     *
     * @return array<int, array<int, string>> identifiant du message => (identifiant de la personne => « @Nom »)
     */
    public function forMessages(array $messages): array
    {
        return $this->mentions->forMessages(array_map(static fn (ConversationMessage $m): int => $m->id(), $messages));
    }

    private function participates(int $userId, int $initiatorGroupId, int $targetGroupId, ?int $conversationId): bool
    {
        return $this->groups->isMember($initiatorGroupId, $userId)
            || $this->groups->isMember($targetGroupId, $userId)
            || ($conversationId !== null && $this->guests->isGuest($conversationId, $userId));
    }

    private function refusal(string $message): ConversationValidationException
    {
        return new ConversationValidationException(['mentions' => $message]);
    }
}
