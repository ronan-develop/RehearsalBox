<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Repository\MysqlUserRepository;
use App\Tests\RepositoryTestCase;

final class MysqlUserRepositoryTest extends RepositoryTestCase
{
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

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $repository = new MysqlUserRepository($this->pdo);

        self::assertNull($repository->findById(9999));
    }

    public function testFindByEmailReturnsMatchingUser(): void
    {
        $repository = new MysqlUserRepository($this->pdo);
        $this->insertUser($repository, 'bob@rehearsalbox.test', 'Bob');

        $found = $repository->findByEmail('bob@rehearsalbox.test');

        self::assertNotNull($found);
        self::assertSame('Bob', $found->displayName());
    }

    public function testFindByEmailReturnsNullWhenNotFound(): void
    {
        $repository = new MysqlUserRepository($this->pdo);

        self::assertNull($repository->findByEmail('inconnu@rehearsalbox.test'));
    }

    public function testSaveTwiceWithSameEmailViolatesUniqueConstraint(): void
    {
        $repository = new MysqlUserRepository($this->pdo);
        $this->insertUser($repository, 'chris@rehearsalbox.test', 'Chris');

        $this->expectException(\PDOException::class);

        $this->insertUser($repository, 'chris@rehearsalbox.test', 'Chris Bis');
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
}
