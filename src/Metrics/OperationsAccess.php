<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;

/**
 * Qui peut LIRE l'exploitation (sauvegardes, production) et les mesures : tout administrateur ACTIF (décision du propriétaire, 2026-10-17 ;
 * avant, un seul compte). Ce sont des pages en lecture seule, sans chemin du serveur, sans clé ni donnée personnelle. Tout autre visiteur
 * (anonyme, musicien, administrateur désactivé) reçoit le même 404 uniforme. La RESTAURATION de la base, elle, reste réservée au seul
 * propriétaire (MetricsAccess) : elle remplace toute la base.
 */
final class OperationsAccess
{
    public function allows(?User $user): bool
    {
        return $user !== null && $user->isActive() && $user->role() === UserRole::Admin;
    }
}
