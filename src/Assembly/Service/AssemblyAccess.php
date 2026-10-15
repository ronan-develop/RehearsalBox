<?php

declare(strict_types=1);

namespace App\Assembly\Service;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Security\Exception\AccessDeniedException;

/** Les comptes rendus d'AG (noms des présents) sont réservés aux administrateurs actifs, vérifié dans chaque service (#361). */
final class AssemblyAccess
{
    /** @throws AccessDeniedException */
    public function assertAdmin(User $actor): void
    {
        if (!$actor->isActive() || $actor->role() !== UserRole::Admin) {
            throw new AccessDeniedException('Accès refusé.');
        }
    }
}
