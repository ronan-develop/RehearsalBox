<?php

declare(strict_types=1);

namespace App\Entity;

/** Fil complet tel que le voit une personne : conversation, label des deux groupes et messages. */
final class ConversationThread
{
    /** @param list<ConversationMessage> $messages */
    public function __construct(
        private readonly Conversation $conversation,
        private readonly string $label,
        private readonly array $messages,
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

    /** @return list<ConversationMessage> */
    public function messages(): array
    {
        return $this->messages;
    }
}
