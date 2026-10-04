<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    private function lockedUser(): User
    {
        return new User(
            id: 7,
            email: 'alice@example.test',
            passwordHash: 'ancien-hash',
            displayName: 'Alice',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 5,
            lockedUntil: new \DateTimeImmutable('+15 minutes'),
        );
    }

    #[Test]
    public function testWithPasswordHashReplacesTheHashAndClearsFailedAttemptsAndLock(): void
    {
        $updated = $this->lockedUser()->withPasswordHash('nouveau-hash');

        self::assertSame('nouveau-hash', $updated->passwordHash());
        self::assertSame(0, $updated->failedLoginAttempts());
        self::assertNull($updated->lockedUntil());
    }

    #[Test]
    public function testWithPasswordHashKeepsEveryOtherField(): void
    {
        $updated = $this->lockedUser()->withPasswordHash('nouveau-hash');

        self::assertSame(7, $updated->id());
        self::assertSame('alice@example.test', $updated->email());
        self::assertSame('Alice', $updated->displayName());
        self::assertSame(UserRole::Musicien, $updated->role());
        self::assertTrue($updated->isActive());
    }

    #[Test]
    public function testWithPasswordHashDoesNotMutateTheOriginalUser(): void
    {
        $original = $this->lockedUser();

        $original->withPasswordHash('nouveau-hash');

        self::assertSame('ancien-hash', $original->passwordHash());
        self::assertSame(5, $original->failedLoginAttempts());
    }

    #[Test]
    public function testNewUserStartsAtSessionVersionZero(): void
    {
        self::assertSame(0, $this->lockedUser()->sessionVersion());
    }

    #[Test]
    public function testWithPasswordHashRevokesOtherSessionsByBumpingTheSessionVersion(): void
    {
        $updated = $this->lockedUser()->withPasswordHash('nouveau-hash');

        self::assertSame(1, $updated->sessionVersion());
    }

    #[Test]
    public function testWithSessionsRevokedBumpsTheVersionAndKeepsThePassword(): void
    {
        $updated = $this->lockedUser()->withSessionsRevoked();

        self::assertSame(1, $updated->sessionVersion());
        self::assertSame('ancien-hash', $updated->passwordHash());
    }

    #[Test]
    public function testOtherWithersPreserveTheSessionVersion(): void
    {
        $user = $this->lockedUser()->withSessionsRevoked()->withSessionsRevoked();

        self::assertSame(2, $user->withResetFailedAttempts()->sessionVersion());
        self::assertSame(2, $user->withFailedLoginAttempt(5, new \DateTimeImmutable(), '+15 minutes')->sessionVersion());
    }

    #[Test]
    public function testWithLockedUntilSetsTheLockAndKeepsEverythingElse(): void
    {
        $until = new \DateTimeImmutable('+7 days');

        $locked = $this->lockedUser()->withResetFailedAttempts()->withLockedUntil($until);

        self::assertEquals($until, $locked->lockedUntil());
        self::assertSame('ancien-hash', $locked->passwordHash());
        self::assertSame(0, $locked->failedLoginAttempts());
    }
}
