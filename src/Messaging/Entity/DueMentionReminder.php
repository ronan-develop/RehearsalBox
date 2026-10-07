<?php

declare(strict_types=1);

namespace App\Messaging\Entity;

/** Relance due : une personne mentionnée n'a pas lu la conversation 24 h après l'e-mail de mention. */
final class DueMentionReminder
{
    public function __construct(
        private readonly int $conversationId,
        private readonly int $userId,
        private readonly string $email,
        private readonly string $mentionerName,
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

    /** Nom de la personne qui l'a mentionnée ('' si son compte a disparu). */
    public function mentionerName(): string
    {
        return $this->mentionerName;
    }
}
