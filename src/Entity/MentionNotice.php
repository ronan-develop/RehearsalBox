<?php

declare(strict_types=1);

namespace App\Entity;

/** Dernier e-mail de mention envoyé à une personne dans une conversation (aucun contenu, aucune adresse). */
final class MentionNotice
{
    public function __construct(
        private readonly \DateTimeImmutable $notifiedAt,
        private readonly ?int $notifiedBy,
        private readonly ?\DateTimeImmutable $remindedAt,
    ) {
    }

    public function notifiedAt(): \DateTimeImmutable
    {
        return $this->notifiedAt;
    }

    /** Auteur de la mention qui a déclenché l'e-mail ; null si son compte a disparu. */
    public function notifiedBy(): ?int
    {
        return $this->notifiedBy;
    }

    public function remindedAt(): ?\DateTimeImmutable
    {
        return $this->remindedAt;
    }
}
