<?php

declare(strict_types=1);

namespace App\Messaging\Service\Direct;

use App\Account\Repository\UserRepositoryInterface;
use App\Messaging\Service\ConversationAccess;
use App\Security\Exception\AccessDeniedException;

/**
 * Qui peut écrire à qui en message direct (#269) : tout membre actif à tout autre membre actif, la confiance entre musiciens
 * primant. Seuls refus : soi-même, une personne inconnue ou inactive, un émetteur inactif. Tous donnent le même refus, qui
 * ne révèle pas l'existence d'un compte.
 */
final class DirectMessagePolicy
{
    public function __construct(private readonly UserRepositoryInterface $users)
    {
    }

    /** @throws AccessDeniedException */
    public function assertCanWrite(int $senderId, int $targetId): void
    {
        $sender = $this->users->findById($senderId);
        $target = $senderId === $targetId ? null : $this->users->findById($targetId);

        if ($sender === null || !$sender->isActive() || $target === null || !$target->isActive()) {
            throw new AccessDeniedException(ConversationAccess::DENIED);
        }
    }
}
