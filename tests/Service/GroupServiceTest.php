<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\GroupUserRole;
use App\Entity\Enum\UserRole;
use App\Entity\LineupMember;
use App\Entity\UpcomingShow;
use App\Entity\User;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Security\Exception\AccessDeniedException;
use App\Service\Contract\GroupFilesPurgerInterface;
use App\Service\Exception\GroupValidationException;
use App\Service\GroupService;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

final class GroupServiceTest extends RepositoryTestCase
{
    private function makeService(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);
        $service = new GroupService($groupRepository, $userRepository);

        return [$service, $groupRepository, $userRepository];
    }

    private function createUser(MysqlUserRepository $userRepository, string $email): User
    {
        return $userRepository->save(new User(
            id: 0,
            email: $email,
            passwordHash: password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]),
            displayName: $email,
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
    }

    #[Test]

    public function testCreateAddsGroup(): void
    {
        [$service] = $this->makeService();

        $group = $service->create('Black Sabbath Tribute', 'metal', '#e63946', 'contact@example.test');

        self::assertSame('Black Sabbath Tribute', $group->name());
    }

    #[Test]

    public function testAddMemberByEmailAddsExistingUser(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $service->create('Groupe Test', null, null, 'contact@example.test');
        $user = $this->createUser($userRepository, 'alice@rehearsalbox.test');

        $service->addMemberByEmail($group->id(), 'alice@rehearsalbox.test');

        self::assertTrue($groupRepository->isMember($group->id(), $user->id()));
    }

    #[Test]

    public function testAddMemberByEmailWithUnknownEmailThrowsInvalidArgument(): void
    {
        [$service] = $this->makeService();
        $group = $service->create('Groupe Test', null, null, 'contact@example.test');

        $this->expectException(\InvalidArgumentException::class);

        $service->addMemberByEmail($group->id(), 'inconnu@rehearsalbox.test');
    }

    #[Test]

    public function testRemoveMemberRemovesExistingMember(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $service->create('Groupe Test', null, null, 'contact@example.test');
        $user = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $service->addMemberByEmail($group->id(), 'bob@rehearsalbox.test');

        $service->removeMember($group->id(), $user->id());

        self::assertFalse($groupRepository->isMember($group->id(), $user->id()));
    }

    #[Test]

    public function testUpdateChangesGroupFields(): void
    {
        [$service] = $this->makeService();
        $group = $service->create('Groupe Test', 'metal', '#e63946', 'contact@example.test');

        $updated = $service->update($group->id(), 'Nouveau Nom', 'punk', '#123456', 'nouveau@example.test');

        self::assertSame('Nouveau Nom', $updated->name());
        self::assertSame('punk', $updated->genre());
        self::assertSame('#123456', $updated->colorHex());
        self::assertSame('nouveau@example.test', $updated->contactEmail());
    }

    #[Test]

    public function testUpdateWithUnknownGroupThrowsInvalidArgument(): void
    {
        [$service] = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);

        $service->update(9999, 'Nom', null, null, 'contact@example.test');
    }

    #[Test]

    public function testDeleteRemovesGroup(): void
    {
        [$service, $groupRepository] = $this->makeService();
        $group = $service->create('Groupe Test', null, null, 'contact@example.test');

        $service->delete($group->id());

        self::assertNull($groupRepository->findById($group->id()));
    }

    #[Test]

    public function testFindAllReturnsEveryGroup(): void
    {
        [$service] = $this->makeService();
        $service->create('Groupe A', null, null, 'contact@example.test');
        $service->create('Groupe B', null, null, 'contact@example.test');

        self::assertCount(2, $service->findAll());
    }

    #[Test]

    public function testUpdateProfileByGestionnaireSavesLineupAndShows(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $service->create('Groupe Test', null, null, 'contact@example.test');
        $manager = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);

        $updated = $service->updateProfile(
            $group->id(),
            [new LineupMember('Alice', 'Guitare')],
            [new UpcomingShow('2026-09-12', 'Le Point Éphémère')],
            $manager->id(),
        );

        self::assertCount(1, $updated->lineup());
        self::assertSame('Alice', $updated->lineup()[0]->name());
        self::assertCount(1, $updated->upcomingShows());
    }

    #[Test]

    public function testUpdateProfileByNonGestionnaireThrowsAccessDenied(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $service->create('Groupe Test', null, null, 'contact@example.test');
        $member = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $member->id());

        $this->expectException(AccessDeniedException::class);

        $service->updateProfile($group->id(), [], [], $member->id());
    }

    #[Test]

    public function testUpdateProfileByNonMemberThrowsAccessDenied(): void
    {
        [$service, , $userRepository] = $this->makeService();
        $group = $service->create('Groupe Test', null, null, 'contact@example.test');
        $stranger = $this->createUser($userRepository, 'chris@rehearsalbox.test');

        $this->expectException(AccessDeniedException::class);

        $service->updateProfile($group->id(), [], [], $stranger->id());
    }

    // --- Suppression d'un groupe : ses fichiers partent avec lui (#222) ----------------------------------

    /** Faux purgeur : mémorise ce qu'on lui demande, dans l'ordre. */
    private function recordingPurger(array $paths): GroupFilesPurgerInterface
    {
        return new class ($paths) implements GroupFilesPurgerInterface {
            /** @var list<string> */
            public array $log = [];

            public function __construct(private readonly array $paths)
            {
            }

            public function filesOf(int $groupId): array
            {
                $this->log[] = 'liste';

                return $this->paths;
            }

            public function remove(array $paths): void
            {
                $this->log[] = 'retrait:' . implode(',', $paths);
            }
        };
    }

    #[Test]
    public function testDeletingAGroupRemovesItsFilesAfterTheGroupIsGone(): void
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $purger = $this->recordingPurger(['/stockage/a.pdf', '/stockage/b.png']);
        $service = new GroupService($groupRepository, new MysqlUserRepository($this->pdo), $purger);
        $group = $service->create('Groupe Test', null, null, 'contact@example.test');

        $service->delete($group->id());

        self::assertNull($groupRepository->findById($group->id()));
        self::assertSame(['liste', 'retrait:/stockage/a.pdf,/stockage/b.png'], $purger->log, 'listés avant la suppression, retirés après');
    }

    #[Test]
    public function testTheFilesAreKeptWhenTheGroupCannotBeDeleted(): void
    {
        $purger = $this->recordingPurger(['/stockage/a.pdf']);
        $groupRepository = $this->createStub(\App\Repository\Contract\GroupRepositoryInterface::class);
        $groupRepository->method('delete')->willThrowException(new \PDOException('contrainte'));
        $service = new GroupService($groupRepository, new MysqlUserRepository($this->pdo), $purger);

        try {
            $service->delete(1);
            self::fail('la suppression échoue');
        } catch (\PDOException) {
        }

        self::assertSame(['liste'], $purger->log, 'aucun fichier retiré si le groupe est toujours là');
    }

    // --- Validation, unicité, erreurs prévisibles (#224) ----------------------------------------------

    #[Test]
    public function testCreateRefusesAnInvalidGroupWithTheReasonOfEachField(): void
    {
        [$service] = $this->makeService();

        try {
            $service->create('', str_repeat('g', 61), 'rouge', 'pas-un-email');
            self::fail('groupe invalide');
        } catch (GroupValidationException $e) {
            self::assertSame(['name', 'genre', 'colorHex', 'contactEmail'], array_keys($e->fields()));
        }
        self::assertSame([], $service->findAll(), 'rien n\'est créé');
    }

    #[Test]
    public function testCreateNormalizesTheNameAndEmptyOptionalFields(): void
    {
        [$service] = $this->makeService();

        $group = $service->create("  Nebula   Sprawl \n", '   ', null, 'contact@example.test');

        self::assertSame('Nebula Sprawl', $group->name());
        self::assertNull($group->genre());
    }

    #[Test]
    public function testTwoGroupsCannotShareANameOrTheSameSlug(): void
    {
        [$service] = $this->makeService();
        $service->create('Nebula Sprawl', null, null, 'a@example.test');

        // Même nom (casse, espaces, accents) : l'adresse publique /groups/nebula-sprawl/space serait ambiguë.
        foreach (['nebula sprawl', 'NEBULA-SPRAWL', 'Nébula   Sprawl'] as $clash) {
            try {
                $service->create($clash, null, null, 'b@example.test');
                self::fail('nom trop proche refusé : ' . $clash);
            } catch (GroupValidationException $e) {
                self::assertArrayHasKey('name', $e->fields(), $clash);
            }
        }
        self::assertCount(1, $service->findAll());
    }

    #[Test]
    public function testAGroupKeepsItsOwnNameOnUpdateButCannotTakeAnotherOne(): void
    {
        [$service] = $this->makeService();
        $first = $service->create('Nebula Sprawl', null, null, 'a@example.test');
        $second = $service->create('Rust Prophet', null, null, 'b@example.test');

        $renamedSame = $service->update($first->id(), 'Nebula Sprawl', 'prog', '#112233', 'a@example.test');
        self::assertSame('prog', $renamedSame->genre());

        $this->expectException(GroupValidationException::class);
        $service->update($second->id(), 'nebula-sprawl', null, null, 'b@example.test');
    }

    #[Test]
    public function testTheProfileIsValidatedAfterTheManagerCheck(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $service->create('Nebula Sprawl', null, null, 'a@example.test');
        $manager = $this->createUser($userRepository, 'gaby@rehearsalbox.test');
        $stranger = $this->createUser($userRepository, 'ivan@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $tooMany = array_map(static fn (int $i): LineupMember => new LineupMember("Membre {$i}", 'Basse'), range(1, 21));

        try {
            $service->updateProfile($group->id(), $tooMany, [], $stranger->id());
            self::fail('un étranger ne peut rien modifier');
        } catch (AccessDeniedException) {
        }
        $this->expectException(GroupValidationException::class);
        $service->updateProfile($group->id(), $tooMany, [new UpcomingShow('2026-02-30', 'Salle')], $manager->id());
    }

    #[Test]
    public function testAddingAnExistingMemberIsHarmlessAndNeverChangesTheirRole(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $service->create('Nebula Sprawl', null, null, 'a@example.test');
        $manager = $this->createUser($userRepository, 'gaby@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);

        $service->addMemberByEmail($group->id(), 'gaby@rehearsalbox.test');
        $service->addMemberByEmail($group->id(), 'gaby@rehearsalbox.test');

        self::assertSame(GroupUserRole::Gestionnaire, $groupRepository->roleOf($group->id(), $manager->id()), 'un gestionnaire n\'est pas rétrogradé en silence');
    }

    #[Test]
    public function testAddingSomeoneToAnUnknownGroupIsACleanRefusal(): void
    {
        [$service, , $userRepository] = $this->makeService();
        $this->createUser($userRepository, 'gaby@rehearsalbox.test');

        $this->expectException(\InvalidArgumentException::class);
        $service->addMemberByEmail(9999, 'gaby@rehearsalbox.test');
    }
}
