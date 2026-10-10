<?php

declare(strict_types=1);

namespace App\Messaging\Entity;

/**
 * Relance due : une personne mentionnée n'a pas lu la conversation 24 h après l'e-mail de mention, ou (#372) l'autre membre d'un
 * message direct n'a pas lu 24 h après l'e-mail « X vous a écrit ».
 */
final class DueMentionReminder
{
    public function __construct(
        private readonly int $conversationId,
        private readonly int $userId,
        private readonly string $email,
        private readonly string $mentionerName,
        private readonly bool $direct = false,
    ) {
    }

    public function conversationId(): int
    {
        return $this->conversationId;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    /** Adresse du compte de la personne : à ne jamais journaliser. */
    public function email(): string
    {
        return $this->email;
    }

    /** Message direct (#372) : l'e-mail dit « vous a écrit » et non « vous a mentionné(e) ». */
    public function isDirect(): bool
    {
        return $this->direct;
    }

    /** Nom de la personne qui l'a mentionnée ou qui a écrit ('' si son compte a disparu). */
    public function mentionerName(): string
    {
        return $this->mentionerName;
    }
}
