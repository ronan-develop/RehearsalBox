<?php

declare(strict_types=1);

namespace App\Assembly\Entity;

/** Une personne de la liste des présences ; `userId` est null pour un non-inscrit saisi à la main. */
final class Attendee
{
    public function __construct(
        public readonly ?int $userId,
        public readonly string $name,
        public readonly Attendance $attendance,
        public readonly ?string $proxyHolder,
    ) {
    }
}
