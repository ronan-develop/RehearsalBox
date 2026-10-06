<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\RequestKind;

/** Une ligne du bloc « Demandes de créneau » : un échange entre groupes ou une réservation libre (#292). */
interface DashboardRequestItem
{
    public function kind(): RequestKind;

    public function requestId(): int;

    public function createdAt(): \DateTimeImmutable;
}
