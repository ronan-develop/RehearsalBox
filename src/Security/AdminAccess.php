<?php

declare(strict_types=1);

namespace App\Security;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Security\Exception\AccessDeniedException;

/** Garde « administrateur actif » des services réservés aux administrateurs (comptes rendus d'AG #361, textes de l'association #472) : vérifiée dans chaque service, en plus de `AuthGuard` dans le contrôleur. */
final class AdminAccess
{
    /** @throws AccessDeniedException */
    public function assertAdmin(User $actor): void
    {
        if (!$actor->isActive() || $actor->role() !== UserRole::Admin) {
            throw new AccessDeniedException('Accès refusé.');
        }
    }
}
