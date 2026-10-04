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
        private readonly int $sessionVersion = 0,
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

    /** Incrémentée pour fermer toutes les sessions ouvertes (changement de mot de passe, compte sécurisé). */
    public function sessionVersion(): int
    {
        return $this->sessionVersion;
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
            $this->sessionVersion,
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
            $this->sessionVersion,
        );
    }

    /**
     * Nouveau mot de passe (déjà haché) : remet aussi à zéro les échecs de connexion et le verrouillage,
     * et ferme toutes les autres sessions (version incrémentée).
     */
    public function withPasswordHash(string $passwordHash): self
    {
        return new self(
            $this->id,
            $this->email,
            $passwordHash,
            $this->displayName,
            $this->role,
            $this->isActive,
            0,
            null,
            $this->sessionVersion + 1,
        );
    }

    /** Ferme toutes les sessions ouvertes, sans toucher au mot de passe. */
    public function withSessionsRevoked(): self
    {
        return new self(
            $this->id,
            $this->email,
            $this->passwordHash,
            $this->displayName,
            $this->role,
            $this->isActive,
            $this->failedLoginAttempts,
            $this->lockedUntil,
            $this->sessionVersion + 1,
        );
    }

    /** Nouveau nom affiché ; ne touche à rien d'autre (pas de session fermée). */
    public function withDisplayName(string $displayName): self
    {
        return new self(
            $this->id,
            $this->email,
            $this->passwordHash,
            $displayName,
            $this->role,
            $this->isActive,
            $this->failedLoginAttempts,
            $this->lockedUntil,
            $this->sessionVersion,
        );
    }

    /**
     * Active ou désactive le compte. La désactivation ferme aussi toutes les
     * sessions ouvertes (version incrémentée) ; la réactivation ne change rien d'autre.
     */
    public function withActive(bool $isActive): self
    {
        return new self(
            $this->id,
            $this->email,
            $this->passwordHash,
            $this->displayName,
            $this->role,
            $isActive,
            $this->failedLoginAttempts,
            $this->lockedUntil,
            $isActive ? $this->sessionVersion : $this->sessionVersion + 1,
        );
    }

    public function withLockedUntil(\DateTimeImmutable $lockedUntil): self
    {
        return new self(
            $this->id,
            $this->email,
            $this->passwordHash,
            $this->displayName,
            $this->role,
            $this->isActive,
            $this->failedLoginAttempts,
            $lockedUntil,
            $this->sessionVersion,
        );
    }
}
