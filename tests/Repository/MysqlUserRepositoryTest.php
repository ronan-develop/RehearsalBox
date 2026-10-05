<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Repository\MysqlUserRepository;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

final class MysqlUserRepositoryTest extends RepositoryTestCase
{
    #[Test]
    public function testSaveThenFindByIdReturnsSameUser(): void
    {
        $repository = new MysqlUserRepository($this->pdo);

        $inserted = $this->insertUser($repository, 'alice@rehearsalbox.test', 'Alice');

        $found = $repository->findById($inserted->id());

        self::assertNotNull($found);
        self::assertSame('alice@rehearsalbox.test', $found->email());
        self::assertSame('Alice', $found->displayName());
        self::assertTrue($found->hasRole(UserRole::Musicien));
    }

    #[Test]

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $repository = new MysqlUserRepository($this->pdo);

        self::assertNull($repository->findById(9999));
    }

    #[Test]

    public function testFindByEmailReturnsMatchingUser(): void
    {
        $repository = new MysqlUserRepository($this->pdo);
        $this->insertUser($repository, 'bob@rehearsalbox.test', 'Bob');

        $found = $repository->findByEmail('bob@rehearsalbox.test');

        self::assertNotNull($found);
        self::assertSame('Bob', $found->displayName());
    }

    #[Test]

    public function testFindByEmailReturnsNullWhenNotFound(): void
    {
        $repository = new MysqlUserRepository($this->pdo);

        self::assertNull($repository->findByEmail('inconnu@rehearsalbox.test'));
    }

    #[Test]

    public function testSaveTwiceWithSameEmailViolatesUniqueConstraint(): void
    {
        $repository = new MysqlUserRepository($this->pdo);
        $this->insertUser($repository, 'chris@rehearsalbox.test', 'Chris');

        $this->expectException(\PDOException::class);

        $this->insertUser($repository, 'chris@rehearsalbox.test', 'Chris Bis');
    }

    #[Test]
    public function testEveryFailedLoginCountsEvenWhenManyRequestsReadTheSameStaleUser(): void
    {
        $repository = new MysqlUserRepository($this->pdo);
        $user = $this->insertUser($repository, 'alice@rehearsalbox.test', 'Alice');
        $now = new \DateTimeImmutable('2026-01-01 10:00:00');

        // Cinq requêtes simultanées ont toutes lu « 0 échec » : aucune ne doit écraser les autres.
        $stale = [$repository->findById($user->id()), $repository->findById($user->id()), $repository->findById($user->id())];
        foreach ($stale as $read) {
            self::assertSame(0, $read->failedLoginAttempts());
            $repository->recordFailedLogin($user->id(), 5, $now, '+15 minutes');
        }

        self::assertSame(3, $repository->findById($user->id())->failedLoginAttempts());
        self::assertFalse($repository->findById($user->id())->isLocked($now));
    }

    #[Test]
    public function testTheAccountIsLockedExactlyWhenTheThresholdIsReached(): void
    {
        $repository = new MysqlUserRepository($this->pdo);
        $user = $this->insertUser($repository, 'alice@rehearsalbox.test', 'Alice');
        $now = new \DateTimeImmutable('2026-01-01 10:00:00');

        for ($i = 1; $i <= 4; ++$i) {
            $repository->recordFailedLogin($user->id(), 5, $now, '+15 minutes');
            self::assertFalse($repository->findById($user->id())->isLocked($now), "après {$i} échecs");
        }
        $repository->recordFailedLogin($user->id(), 5, $now, '+15 minutes');

        $locked = $repository->findById($user->id());
        self::assertTrue($locked->isLocked($now));
        self::assertEquals(new \DateTimeImmutable('2026-01-01 10:15:00'), $locked->lockedUntil());
    }

    #[Test]
    public function testTheCounterIsCappedSoItNeverOverflowsItsColumn(): void
    {
        $repository = new MysqlUserRepository($this->pdo);
        $user = $this->insertUser($repository, 'alice@rehearsalbox.test', 'Alice');
        $now = new \DateTimeImmutable('2026-01-01 10:00:00');

        for ($i = 0; $i < 120; ++$i) {
            $repository->recordFailedLogin($user->id(), 5, $now, '+15 minutes');
        }

        self::assertSame(100, $repository->findById($user->id())->failedLoginAttempts());
    }

    #[Test]
    public function testResettingFailedLoginsClearsTheCounterAndTheLockOnly(): void
    {
        $repository = new MysqlUserRepository($this->pdo);
        $user = $this->insertUser($repository, 'alice@rehearsalbox.test', 'Alice');
        $now = new \DateTimeImmutable('2026-01-01 10:00:00');
        for ($i = 0; $i < 5; ++$i) {
            $repository->recordFailedLogin($user->id(), 5, $now, '+15 minutes');
        }

        $repository->resetFailedLogins($user->id());

        $after = $repository->findById($user->id());
        self::assertSame(0, $after->failedLoginAttempts());
        self::assertNull($after->lockedUntil());
        self::assertSame('Alice', $after->displayName());
        self::assertSame($user->sessionVersion(), $after->sessionVersion());
    }

    private function insertUser(MysqlUserRepository $repository, string $email, string $displayName): User
    {
        $user = new User(
            id: 0,
            email: $email,
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: $displayName,
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        );

        return $repository->save($user);
    }

    #[Test]
    public function testSessionVersionDefaultsToZeroAndIsPersistedOnUpdate(): void
    {
        $repository = new MysqlUserRepository($this->pdo);
        $user = $this->insertUser($repository, 'carole@rehearsalbox.test', 'Carole');
        self::assertSame(0, $user->sessionVersion());

        $repository->save($user->withSessionsRevoked());

        self::assertSame(1, $repository->findById($user->id())->sessionVersion());
    }

    #[Test]
    public function testFindAllReturnsEveryAccountOrderedByDisplayName(): void
    {
        $repository = new MysqlUserRepository($this->pdo);
        $this->insertUser($repository, 'zoe@rehearsalbox.test', 'Zoé');
        $this->insertUser($repository, 'alice@rehearsalbox.test', 'Alice');

        $all = $repository->findAll();

        self::assertSame(['Alice', 'Zoé'], array_map(static fn (User $u): string => $u->displayName(), $all));
    }

    #[Test]
    public function testCountActiveAdminsIgnoresInactiveAdminsAndMusicians(): void
    {
        $repository = new MysqlUserRepository($this->pdo);
        $admin = $repository->save(new User(0, 'a@rehearsalbox.test', 'h', 'Admin A', UserRole::Admin, true, 0, null));
        $repository->save(new User(0, 'b@rehearsalbox.test', 'h', 'Admin B', UserRole::Admin, false, 0, null));
        $this->insertUser($repository, 'm@rehearsalbox.test', 'Musicien');

        self::assertSame(1, $repository->countActiveAdmins());

        $repository->save($admin->withActive(false));
        self::assertSame(0, $repository->countActiveAdmins());
    }
}
