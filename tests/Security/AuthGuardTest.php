<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Security\AuthGuard;
use App\Security\Exception\AccessDeniedException;
use App\Service\Contract\AuthServiceInterface;
use PHPUnit\Framework\TestCase;

final class AuthGuardTest extends TestCase
{
    private function user(UserRole $role): User
    {
        return new User(1, 'a@a.test', 'hash', 'Test', $role, true, 0, null);
    }

    private function authServiceReturning(?User $user): AuthServiceInterface
    {
        return new class ($user) implements AuthServiceInterface {
            public function __construct(private readonly ?User $user)
            {
            }

            public function attempt(string $email, string $plainPassword): ?User
            {
                return null;
            }

            public function currentUser(): ?User
            {
                return $this->user;
            }

            public function logout(): void
            {
            }
        };
    }

    public function testRequireLoginPassesWhenUserIsAuthenticated(): void
    {
        $guard = new AuthGuard($this->authServiceReturning($this->user(UserRole::Musicien)));

        $guard->requireLogin();

        $this->expectNotToPerformAssertions();
    }

    public function testRequireLoginThrowsWhenNoUser(): void
    {
        $guard = new AuthGuard($this->authServiceReturning(null));

        $this->expectException(AccessDeniedException::class);

        $guard->requireLogin();
    }

    public function testRequireRolePassesWhenUserHasRole(): void
    {
        $guard = new AuthGuard($this->authServiceReturning($this->user(UserRole::Admin)));

        $guard->requireRole(UserRole::Admin);

        $this->expectNotToPerformAssertions();
    }

    public function testRequireRoleThrowsWhenUserHasWrongRole(): void
    {
        $guard = new AuthGuard($this->authServiceReturning($this->user(UserRole::Musicien)));

        $this->expectException(AccessDeniedException::class);

        $guard->requireRole(UserRole::Admin);
    }

    public function testRequireRoleThrowsWhenNoUser(): void
    {
        $guard = new AuthGuard($this->authServiceReturning(null));

        $this->expectException(AccessDeniedException::class);

        $guard->requireRole(UserRole::Admin);
    }
}
