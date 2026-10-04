<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Repository\MysqlPasswordResetRepository;
use App\Repository\MysqlUserRepository;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

final class MysqlPasswordResetRepositoryTest extends RepositoryTestCase
{
    private function insertUser(string $email): User
    {
        return (new MysqlUserRepository($this->pdo))->save(new User(
            id: 0,
            email: $email,
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: 'Utilisateur',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
    }

    private function hash(string $seed): string
    {
        return hash('sha256', $seed);
    }

    #[Test]
    public function testConsumeReturnsTheUserIdOfAValidToken(): void
    {
        $repository = new MysqlPasswordResetRepository($this->pdo);
        $user = $this->insertUser('alice@rehearsalbox.test');
        $now = new \DateTimeImmutable('2026-10-04 12:00:00');
        $repository->create($user->id(), $this->hash('a'), $now->modify('+1 hour'), $now);

        self::assertSame($user->id(), $repository->consume($this->hash('a'), $now->modify('+10 minutes')));
    }

    #[Test]
    public function testConsumeIsSingleUse(): void
    {
        $repository = new MysqlPasswordResetRepository($this->pdo);
        $user = $this->insertUser('alice@rehearsalbox.test');
        $now = new \DateTimeImmutable('2026-10-04 12:00:00');
        $repository->create($user->id(), $this->hash('a'), $now->modify('+1 hour'), $now);

        self::assertSame($user->id(), $repository->consume($this->hash('a'), $now));
        self::assertNull($repository->consume($this->hash('a'), $now));
    }

    #[Test]
    public function testConsumeRejectsAnExpiredToken(): void
    {
        $repository = new MysqlPasswordResetRepository($this->pdo);
        $user = $this->insertUser('alice@rehearsalbox.test');
        $now = new \DateTimeImmutable('2026-10-04 12:00:00');
        $repository->create($user->id(), $this->hash('a'), $now->modify('+1 hour'), $now);

        self::assertNull($repository->consume($this->hash('a'), $now->modify('+1 hour')));
        self::assertNull($repository->consume($this->hash('a'), $now->modify('+2 hours')));
    }

    #[Test]
    public function testConsumeRejectsAnUnknownToken(): void
    {
        $repository = new MysqlPasswordResetRepository($this->pdo);

        self::assertNull($repository->consume($this->hash('inconnu'), new \DateTimeImmutable('2026-10-04 12:00:00')));
    }

    #[Test]
    public function testExpiredTokenIsNotMarkedAsUsed(): void
    {
        $repository = new MysqlPasswordResetRepository($this->pdo);
        $user = $this->insertUser('alice@rehearsalbox.test');
        $now = new \DateTimeImmutable('2026-10-04 12:00:00');
        $repository->create($user->id(), $this->hash('a'), $now->modify('+1 hour'), $now);

        $repository->consume($this->hash('a'), $now->modify('+2 hours'));

        $usedAt = $this->pdo->query('SELECT used_at FROM password_resets')->fetchColumn();
        self::assertNull($usedAt);
    }

    #[Test]
    public function testInvalidateAllForUserCancelsItsPendingTokensOnly(): void
    {
        $repository = new MysqlPasswordResetRepository($this->pdo);
        $alice = $this->insertUser('alice@rehearsalbox.test');
        $bob = $this->insertUser('bob@rehearsalbox.test');
        $now = new \DateTimeImmutable('2026-10-04 12:00:00');
        $repository->create($alice->id(), $this->hash('a1'), $now->modify('+1 hour'), $now);
        $repository->create($alice->id(), $this->hash('a2'), $now->modify('+1 hour'), $now);
        $repository->create($bob->id(), $this->hash('b1'), $now->modify('+1 hour'), $now);

        $repository->invalidateAllForUser($alice->id(), $now);

        self::assertNull($repository->consume($this->hash('a1'), $now));
        self::assertNull($repository->consume($this->hash('a2'), $now));
        self::assertSame($bob->id(), $repository->consume($this->hash('b1'), $now));
    }

    #[Test]
    public function testCountCreatedSinceCountsOnlyTheUsersRecentRequests(): void
    {
        $repository = new MysqlPasswordResetRepository($this->pdo);
        $alice = $this->insertUser('alice@rehearsalbox.test');
        $bob = $this->insertUser('bob@rehearsalbox.test');
        $now = new \DateTimeImmutable('2026-10-04 12:00:00');
        $repository->create($alice->id(), $this->hash('old'), $now->modify('-1 hour'), $now->modify('-2 hours'));
        $repository->create($alice->id(), $this->hash('a1'), $now->modify('+1 hour'), $now->modify('-30 minutes'));
        $repository->create($alice->id(), $this->hash('a2'), $now->modify('+1 hour'), $now->modify('-5 minutes'));
        $repository->create($bob->id(), $this->hash('b1'), $now->modify('+1 hour'), $now->modify('-5 minutes'));

        self::assertSame(2, $repository->countCreatedSince($alice->id(), $now->modify('-1 hour')));
    }

    #[Test]
    public function testTokensAreDeletedWithTheirUser(): void
    {
        $repository = new MysqlPasswordResetRepository($this->pdo);
        $alice = $this->insertUser('alice@rehearsalbox.test');
        $now = new \DateTimeImmutable('2026-10-04 12:00:00');
        $repository->create($alice->id(), $this->hash('a'), $now->modify('+1 hour'), $now);

        $this->pdo->exec('DELETE FROM users WHERE id = ' . $alice->id());

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn());
    }

    #[Test]
    public function testTokenHashMustBeUnique(): void
    {
        $repository = new MysqlPasswordResetRepository($this->pdo);
        $alice = $this->insertUser('alice@rehearsalbox.test');
        $now = new \DateTimeImmutable('2026-10-04 12:00:00');
        $repository->create($alice->id(), $this->hash('a'), $now->modify('+1 hour'), $now);

        $this->expectException(\PDOException::class);

        $repository->create($alice->id(), $this->hash('a'), $now->modify('+1 hour'), $now);
    }

    #[Test]
    public function testTokensOfDifferentPurposesAreIsolated(): void
    {
        $repository = new MysqlPasswordResetRepository($this->pdo);
        $alice = $this->insertUser('alice@rehearsalbox.test');
        $now = new \DateTimeImmutable('2026-10-04 12:00:00');
        $repository->create($alice->id(), $this->hash('reset'), $now->modify('+1 hour'), $now);
        $repository->create($alice->id(), $this->hash('alert'), $now->modify('+24 hours'), $now, 'alert');

        // Un jeton d'alerte ne peut pas servir à réinitialiser, ni l'inverse.
        self::assertNull($repository->consume($this->hash('alert'), $now));
        self::assertNull($repository->consume($this->hash('reset'), $now, 'alert'));
        self::assertSame($alice->id(), $repository->consume($this->hash('alert'), $now, 'alert'));
        self::assertSame($alice->id(), $repository->consume($this->hash('reset'), $now));
    }

    #[Test]
    public function testInvalidateAndCountOnlyConcernTheGivenPurpose(): void
    {
        $repository = new MysqlPasswordResetRepository($this->pdo);
        $alice = $this->insertUser('alice@rehearsalbox.test');
        $now = new \DateTimeImmutable('2026-10-04 12:00:00');
        $repository->create($alice->id(), $this->hash('reset'), $now->modify('+1 hour'), $now);
        $repository->create($alice->id(), $this->hash('alert'), $now->modify('+24 hours'), $now, 'alert');

        $repository->invalidateAllForUser($alice->id(), $now);

        self::assertNull($repository->consume($this->hash('reset'), $now));
        self::assertSame($alice->id(), $repository->consume($this->hash('alert'), $now, 'alert'));
        self::assertSame(1, $repository->countCreatedSince($alice->id(), $now->modify('-1 hour'), 'alert'));
        self::assertSame(1, $repository->countCreatedSince($alice->id(), $now->modify('-1 hour')));
    }
}
