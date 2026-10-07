<?php

declare(strict_types=1);

namespace App\Messaging\Service;

use App\Messaging\Repository\MessageVersionRepositoryInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Oubli des anciennes versions d'un message corrigé (#225) : une information sensible collée puis corrigée ne reste pas en base
 * indéfiniment. Elles servent seulement à l'audit, pendant RETENTION ; passé ce délai elles sont supprimées (tâche planifiée).
 */
final class MessageVersionPurge
{
    public const RETENTION = '-30 days';

    public function __construct(
        private readonly MessageVersionRepositoryInterface $versions,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return int nombre de versions supprimées */
    public function purge(): int
    {
        return $this->versions->purgeBefore($this->clock->now()->modify(self::RETENTION));
    }
}
