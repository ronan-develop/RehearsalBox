<?php

declare(strict_types=1);

namespace App\Entity;

/** Relance à envoyer à l'adresse de contact d'un groupe : un message de l'autre côté est resté sans lecture. */
final class DueReminder
{
    public function __construct(
        private readonly int $conversationId,
        private readonly int $groupId,
        private readonly string $groupName,
        private readonly string $contactEmail,
        private readonly string $counterpartName,
        private readonly \DateTimeImmutable $since,
    ) {
    }

    public function conversationId(): int
    {
        return $this->conversationId;
    }

    public function groupId(): int
    {
        return $this->groupId;
    }

    public function groupName(): string
    {
        return $this->groupName;
    }

    public function contactEmail(): string
    {
        return $this->contactEmail;
    }

    /** Nom du groupe de l'autre côté de la conversation (celui qui attend une réponse). */
    public function counterpartName(): string
    {
        return $this->counterpartName;
    }

    /** Date du plus ancien message non lu concerné. */
    public function since(): \DateTimeImmutable
    {
        return $this->since;
    }
}
