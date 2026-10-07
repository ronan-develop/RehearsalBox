<?php

declare(strict_types=1);

namespace App\Planning\Entity;

/** Qui a fait une demande de créneau (#263) : le groupe demandeur et la personne, toujours ensemble. */
final class Requester
{
    public function __construct(private readonly int $groupId, private readonly int $userId)
    {
    }

    public function groupId(): int
    {
        return $this->groupId;
    }

    public function userId(): int
    {
        return $this->userId;
    }
}
