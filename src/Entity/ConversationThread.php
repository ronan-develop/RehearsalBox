<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Fil tel que le voit une personne : conversation, label des deux groupes, messages (tous, ou seulement les nouveaux
 * lors d'une lecture incrémentale), qui écrit en ce moment, « vu par » et groupe de chaque auteur (pastille).
 */
final class ConversationThread
{
    /**
     * @param list<ConversationMessage> $messages
     * @param list<string>              $typing       noms des autres personnes qui écrivent
     * @param array<int, Group|null>    $authorGroups groupe de chaque auteur (null : ambigu, membre des deux groupes ou d'aucun)
     */
    public function __construct(
        private readonly Conversation $conversation,
        private readonly string $label,
        private readonly array $messages,
        private readonly array $typing,
        private readonly ?SeenReceipt $seen,
        private readonly array $authorGroups,
        private readonly ?int $firstUnreadId = null,
    ) {
    }

    public function conversation(): Conversation
    {
        return $this->conversation;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** Titre choisi par les membres, sinon le label des deux groupes. */
    public function displayTitle(): string
    {
        return $this->conversation->title() ?? $this->label;
    }

    /** @return list<ConversationMessage> */
    public function messages(): array
    {
        return $this->messages;
    }

    /** @return list<string> */
    public function typing(): array
    {
        return $this->typing;
    }

    public function seen(): ?SeenReceipt
    {
        return $this->seen;
    }

    /** Premier message reçu depuis ma dernière lecture (séparateur « Messages non lus »), null s'il n'y en a pas. */
    public function firstUnreadId(): ?int
    {
        return $this->firstUnreadId;
    }

    public function authorGroup(int $authorId): ?Group
    {
        return $this->authorGroups[$authorId] ?? null;
    }
}
