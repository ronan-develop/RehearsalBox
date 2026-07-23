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

    public function failedLoginAttempts(): int
    {
        return $this->failedLoginAttempts;
    }

    public function lockedUntil(): ?\DateTimeImmutable
    {
        return $this->lockedUntil;
    }

    /** Verrouille temporairement le compte après le seuil d'échecs (cf. plan §10.4). */
    public function withFailedLoginAttempt(int $maxAttempts, \DateTimeImmutable $now, string $lockDuration): self
    {
        $attempts = $this->failedLoginAttempts + 1;
        $lockedUntil = $attempts >= $maxAttempts ? $now->modify($lockDuration) : $this->lockedUntil;

        return new self(
            $this->id,
            $this->email,
            $this->passwordHash,
            $this->displayName,
            $this->role,
            $this->isActive,
            $attempts,
            $lockedUntil,
        );
    }

    public function withResetFailedAttempts(): self
    {
        return new self(
            $this->id,
            $this->email,
            $this->passwordHash,
            $this->displayName,
            $this->role,
            $this->isActive,
            0,
            null,
        );
    }
}
