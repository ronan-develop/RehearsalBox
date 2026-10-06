<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\GroupDocument;
use App\Entity\User;
use App\Repository\MysqlGroupDocumentRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Tests\RepositoryTestCase;
use App\Tests\TestDatabase;
use PHPUnit\Framework\Attributes\Test;

final class MysqlGroupDocumentRepositoryTest extends RepositoryTestCase
{
    private function makeGroupAndUser(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $user = $userRepository->save(new User(
            id: 0,
            email: 'alice@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]),
            displayName: 'Alice',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));

        return [$group, $user];
    }

    #[Test]

    public function testSaveThenFindByIdReturnsSameDocument(): void
    {
        $repository = new MysqlGroupDocumentRepository($this->pdo);
        [$group, $user] = $this->makeGroupAndUser();

        $inserted = $repository->save(new GroupDocument(0, $group->id(), 'fiche-technique.pdf', 'a1b2c3.pdf', 'application/pdf', 12345, $user->id()));

        $found = $repository->findById($inserted->id());

        self::assertNotNull($found);
        self::assertSame('fiche-technique.pdf', $found->originalName());
        self::assertSame('a1b2c3.pdf', $found->storedName());
        self::assertSame($group->id(), $found->groupId());
    }

    #[Test]

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $repository = new MysqlGroupDocumentRepository($this->pdo);

        self::assertNull($repository->findById(9999));
    }

    #[Test]

    public function testFindByGroupReturnsOnlyDocumentsOfThatGroup(): void
    {
        $repository = new MysqlGroupDocumentRepository($this->pdo);
        $groupRepository = new MysqlGroupRepository($this->pdo);
        [$group, $user] = $this->makeGroupAndUser();
        $otherGroup = $groupRepository->save(new Group(0, 'Autre Groupe', null, null, 'contact@example.test'));

        $repository->save(new GroupDocument(0, $group->id(), 'a.pdf', 'stored-a.pdf', 'application/pdf', 100, $user->id()));
        $repository->save(new GroupDocument(0, $otherGroup->id(), 'b.pdf', 'stored-b.pdf', 'application/pdf', 100, $user->id()));

        $found = $repository->findByGroup($group->id());

        self::assertCount(1, $found);
        self::assertSame('a.pdf', $found[0]->originalName());
    }

    #[Test]

    public function testCountByGroupCountsOnlyThatGroup(): void
    {
        $repository = new MysqlGroupDocumentRepository($this->pdo);
        [$group, $user] = $this->makeGroupAndUser();
        $repository->save(new GroupDocument(0, $group->id(), 'a.pdf', 'stored-a.pdf', 'application/pdf', 100, $user->id()));
        $repository->save(new GroupDocument(0, $group->id(), 'b.pdf', 'stored-b.pdf', 'application/pdf', 100, $user->id()));

        self::assertSame(2, $repository->countByGroup($group->id()));
    }

    #[Test]

    public function testDeleteThenFindByIdReturnsNull(): void
    {
        $repository = new MysqlGroupDocumentRepository($this->pdo);
        [$group, $user] = $this->makeGroupAndUser();
        $inserted = $repository->save(new GroupDocument(0, $group->id(), 'a.pdf', 'stored-a.pdf', 'application/pdf', 100, $user->id()));

        $repository->delete($inserted->id());

        self::assertNull($repository->findById($inserted->id()));
    }

    #[Test]

    public function testDeleteCascadesWhenGroupIsDeleted(): void
    {
        $repository = new MysqlGroupDocumentRepository($this->pdo);
        $groupRepository = new MysqlGroupRepository($this->pdo);
        [$group, $user] = $this->makeGroupAndUser();
        $inserted = $repository->save(new GroupDocument(0, $group->id(), 'a.pdf', 'stored-a.pdf', 'application/pdf', 100, $user->id()));

        $groupRepository->delete($group->id());

        self::assertNull($repository->findById($inserted->id()));
    }

    #[Test]
    public function testTheQuotaLockMakesUploadsOfTheSameGroupWaitForEachOther(): void
    {
        [$group] = $this->makeGroupAndUser();
        $other = (new MysqlGroupRepository($this->pdo))->save(new Group(0, 'Autre groupe', null, null, 'autre@example.test'));
        $second = TestDatabase::connection(); // une AUTRE connexion : un autre envoi simultané
        $second->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $this->pdo->beginTransaction();
        try {
            (new MysqlGroupDocumentRepository($this->pdo))->lockGroupQuota($group->id());

            $second->beginTransaction();
            try {
                (new MysqlGroupDocumentRepository($second))->lockGroupQuota($group->id());
                self::fail('le second envoi du même groupe doit attendre le premier');
            } catch (\PDOException $e) {
                self::assertSame(1205, $e->errorInfo[1] ?? null, 'délai d\'attente du verrou : il était bien bloqué');
            }
            $second->rollBack();

            // Un autre groupe n'est jamais gêné.
            $second->beginTransaction();
            (new MysqlGroupDocumentRepository($second))->lockGroupQuota($other->id());
            $second->rollBack();
        } finally {
            $this->pdo->rollBack();
        }
    }
}
