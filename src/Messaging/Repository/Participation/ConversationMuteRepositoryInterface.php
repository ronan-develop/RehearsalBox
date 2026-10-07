<?php

declare(strict_types=1);

namespace App\Messaging\Repository\Participation;

/** Sourdine d'une conversation (#210) : un interrupteur personnel, sans effet sur les autres participants. */
interface ConversationMuteRepositoryInterface
{
    public function isMuted(int $conversationId, int $userId): bool;

    /** Idempotent : poser deux fois la même valeur est sans effet ; la date de lecture et l'archivage ne bougent pas. */
    public function setMuted(int $conversationId, int $userId, bool $muted): void;
}
