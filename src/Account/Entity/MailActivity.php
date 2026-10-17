<?php

declare(strict_types=1);

namespace App\Account\Entity;

/** Dernière demande de lien de mot de passe d'un compte (#502) : quand elle a été faite, et quand le mail a été accepté par le transport (null = rien n'est parti). */
final class MailActivity
{
    public function __construct(
        public readonly \DateTimeImmutable $requestedAt,
        public readonly ?\DateTimeImmutable $mailSentAt,
    ) {
    }
}
