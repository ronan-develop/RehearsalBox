<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\UserRole;

final class User
{
    public function __construct(
        private readonly int $id,
        private readonly string $email,
        private readonly string $passwordHash,
        private readonly string $displayName,
        private readonly UserRole $role,
        private readonly bool $isActive,
        private readonly int $failedLoginAttempts,
        private readonly ?\DateTimeImmutable $lockedUntil,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function role(): UserRole
    {
        return $this->role;
    }

    public function hasRole(UserRole $role): bool
    {
        return $this->role === $role;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function isLocked(\DateTimeImmutable $now): bool
    {
        return $this->lockedUntil !== null && $this->lockedUntil > $now;
    }
}
