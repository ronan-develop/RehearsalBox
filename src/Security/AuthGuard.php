<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Security\Exception\AccessDeniedException;
use App\Security\Exception\UnauthenticatedException;
use App\Service\Contract\AuthServiceInterface;

final class AuthGuard
{
    public function __construct(private readonly AuthServiceInterface $authService)
    {
    }

    public function requireLogin(): User
    {
        $user = $this->authService->currentUser();
        if ($user === null) {
            throw new UnauthenticatedException('Connexion requise.');
        }

        return $user;
    }

    public function requireRole(UserRole $role): User
    {
        $user = $this->requireLogin();

        if (!$user->hasRole($role)) {
            throw new AccessDeniedException("Rôle {$role->value} requis.");
        }

        return $user;
    }

    public function currentUserOrNull(): ?User
    {
        return $this->authService->currentUser();
    }
}
