<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\ConversationMuteRepositoryInterface;
use App\Security\Exception\AccessDeniedException;

/**
 * Sourdine d'une conversation (#210) : un interrupteur personnel, réservé aux participants (membres des deux groupes ou
 * invités). Une conversation interdite et une conversation inexistante produisent le même refus. N'agit que sur la
 * personne connectée : jamais sur les autres participants.
 */
final class ConversationMuteService
{
    public function __construct(
        private readonly ConversationAccess $access,
        private readonly ConversationMuteRepositoryInterface $mutes,
    ) {
    }

    /** @throws AccessDeniedException */
    public function mute(int $userId, int $conversationId): void
    {
        $this->set($userId, $conversationId, true);
    }

    /** @throws AccessDeniedException */
    public function unmute(int $userId, int $conversationId): void
    {
        $this->set($userId, $conversationId, false);
    }

    private function set(int $userId, int $conversationId, bool $muted): void
    {
        $this->access->participant($userId, $conversationId);
        $this->mutes->setMuted($conversationId, $userId, $muted);
    }
}
