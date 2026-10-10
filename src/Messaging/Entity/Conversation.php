<?php

declare(strict_types=1);

namespace App\Messaging\Entity;

/**
 * Échange entre deux groupes (le groupe qui l'ouvre, l'initiateur, et celui qui est visé) OU message direct entre deux
 * personnes (#269) : alors les deux groupes sont absents et la paire est ordonnée (la plus petite identité d'abord).
 */
final class Conversation
{
    public function __construct(
        private readonly int $id,
        private readonly ?int $initiatorGroupId,
        private readonly ?int $targetGroupId,
        private readonly ?string $title,
        private readonly \DateTimeImmutable $createdAt,
        private readonly ?int $createdBy = null,
        private readonly ?\DateTimeImmutable $deletedAt = null,
        private readonly ?int $directLowUserId = null,
        private readonly ?int $directHighUserId = null,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    /** Null pour un message direct. */
    public function initiatorGroupId(): ?int
    {
        return $this->initiatorGroupId;
    }

    /** Null pour un message direct. */
    public function targetGroupId(): ?int
    {
        return $this->targetGroupId;
    }

    /** Titre choisi par les membres ; null tant que la conversation n'a pas été nommée. */
    public function title(): ?string
    {
        return $this->title;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Personne qui a ouvert la conversation ; null si son compte a disparu. Seule elle peut la supprimer. */
    public function createdBy(): ?int
    {
        return $this->createdBy;
    }

    /** Date de mise à la corbeille, null si la conversation est en service. */
    public function deletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isDirect(): bool
    {
        return $this->directLowUserId !== null;
    }

    public function hasDirectParticipant(int $userId): bool
    {
        return $this->isDirect() && ($userId === $this->directLowUserId || $userId === $this->directHighUserId);
    }

    /** L'autre personne d'un message direct ; null si la conversation est de groupe ou si $userId n'en fait pas partie. */
    public function otherParticipantOf(int $userId): ?int
    {
        if (!$this->hasDirectParticipant($userId)) {
            return null;
        }

        return $userId === $this->directLowUserId ? $this->directHighUserId : $this->directLowUserId;
    }
}
