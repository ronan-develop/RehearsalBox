<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;

/**
 * Qui a le droit de voir le tableau de bord des mesures (#194) : UN seul compte, désigné par `metrics.viewer_email` dans
 * `config.local.php` (jamais dans le dépôt). Ce n'est donc pas « tous les administrateurs ». Défense en profondeur : le compte
 * doit aussi être actif et administrateur. Sans adresse configurée, personne.
 */
final class MetricsAccess
{
    public function __construct(private readonly string $viewerEmail)
    {
    }

    public function allows(?User $user): bool
    {
        return $this->viewerEmail !== ''
            && $user !== null
            && $user->isActive()
            && $user->role() === UserRole::Admin
            && strcasecmp($user->email(), $this->viewerEmail) === 0;
    }
}
