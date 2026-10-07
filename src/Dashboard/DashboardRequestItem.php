<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Dashboard\RequestKind;

/** Une ligne du bloc « Demandes de créneau » : un échange entre groupes ou une réservation libre (#292). */
interface DashboardRequestItem
{
    public function kind(): RequestKind;

    public function requestId(): int;

    public function createdAt(): \DateTimeImmutable;
}
