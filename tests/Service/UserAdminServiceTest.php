<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\GroupUserRole;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Tests\Support\FastPasswordHasher;
use App\Security\PasswordPolicy;
use App\Service\Exception\UserAdminRuleException;
use App\Service\Exception\UserNotFoundException;
use App\Service\Exception\UserValidationException;
use App\Service\UserAdminService;
use App\Service\UserProvisioningService;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class UserAdminServiceTest extends RepositoryTestCase
{
    private MysqlUserRepository $users;
    private MysqlGroupRepository $groups;
    private UserAdminService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->groups = new MysqlGroupRepository($this->pdo);
        $this->service = new UserAdminService(
            $this->users,
            $this->groups,
            new UserProvisioningService($this->users, new FastPasswordHasher(), new PasswordPolicy()),
        );
    }

    private function user(string $email, string $name, UserRole $role = UserRole::Musicien, bool $active = true): User
    {
        return $this->users->save(new User(0, $email, 'hash', $name, $role, $active, 0, null));
    }

    private function group(string $name): Group
    {
        return $this->groups->save(new Group(0, $name, null, null, 'contact@example.test'));
    }

    // --- Liste ---------------------------------------------------------------

    #[Test]
    public function testListShowsEveryAccountWithItsGroupsAndLockState(): void
    {
        $alice = $this->user('alice@rehearsalbox.test', 'Alice');
        $bob = $this->user('bob@rehearsalbox.test', 'Bob');
        $rock = $this->group('Rock');
        $punk = $this->group('Punk');
        $this->groups->addMember($rock->id(), $alice->id());
        $this->groups->addMember($punk->id(), $alice->id(), GroupUserRole::Gestionnaire);
        $this->users->save($bob->withLockedUntil(new \DateTimeImmutable('+1 day')));

        $items = $this->service->listUsers(new \DateTimeImmutable());

        self::assertCount(2, $items);
        self::assertSame('Alice', $items[0]->user()->displayName());
        self::assertSame(['Punk', 'Rock'], array_map(static fn (Group $g): string => $g->name(), $items[0]->groups()));
        self::assertFalse($items[0]->isLocked());
        self::assertSame([], $items[1]->groups());
        self::assertTrue($items[1]->isLocked());
    }

    // --- Création -------------------------------------------------------------

    #[Test]
    public function testCreateMakesAnActiveAccountWithoutAKnownPassword(): void
    {
        $user = $this->service->create('younasse@rehearsalbox.test', 'Younasse', UserRole::Musicien, null);

        self::assertTrue($user->isActive());
        self::assertSame(UserRole::Musicien, $user->role());
        self::assertFalse((new FastPasswordHasher())->verify('', $user->passwordHash()));
    }

    #[Test]
    public function testCreateCanAttachTheNewAccountToAGroup(): void
    {
        $group = $this->group('The Office');

        $user = $this->service->create('younasse@rehearsalbox.test', 'Younasse', UserRole::Musicien, $group->id());

        self::assertTrue($this->groups->isMember($group->id(), $user->id()));
        self::assertSame(GroupUserRole::Membre, $this->groups->roleOf($group->id(), $user->id()));
    }

    #[Test]
    public function testCreateWithUnknownGroupFailsAndCreatesNothing(): void
    {
        try {
            $this->service->create('younasse@rehearsalbox.test', 'Younasse', UserRole::Musicien, 9999);
            self::fail('UserValidationException attendue.');
        } catch (UserValidationException $e) {
            self::assertArrayHasKey('groupId', $e->fields());
        }

        self::assertNull($this->users->findByEmail('younasse@rehearsalbox.test'), 'aucun compte à moitié créé');
    }

    #[Test]
    public function testCreateRejectsAnInvalidOrExistingEmail(): void
    {
        $this->user('alice@rehearsalbox.test', 'Alice');

        $this->expectException(UserValidationException::class);

        $this->service->create('alice@rehearsalbox.test', 'Autre', UserRole::Musicien, null);
    }

    // --- Activation ------------------------------------------------------------

    #[Test]
    public function testDeactivateClosesTheSessionsAndKeepsTheAccount(): void
    {
        $admin = $this->user('admin@rehearsalbox.test', 'Admin', UserRole::Admin);
        $alice = $this->user('alice@rehearsalbox.test', 'Alice');

        $updated = $this->service->setActive($alice->id(), false, $admin->id());

        self::assertFalse($updated->isActive());
        self::assertSame($alice->sessionVersion() + 1, $updated->sessionVersion());
        self::assertNotNull($this->users->findById($alice->id()));
    }

    #[Test]
    public function testReactivateRestoresTheAccount(): void
    {
        $admin = $this->user('admin@rehearsalbox.test', 'Admin', UserRole::Admin);
        $alice = $this->user('alice@rehearsalbox.test', 'Alice', UserRole::Musicien, false);

        self::assertTrue($this->service->setActive($alice->id(), true, $admin->id())->isActive());
    }

    #[Test]
    public function testAnAdminCannotDeactivateThemselves(): void
    {
        $admin = $this->user('admin@rehearsalbox.test', 'Admin', UserRole::Admin);
        $this->user('autre@rehearsalbox.test', 'Autre Admin', UserRole::Admin);

        $this->expectException(UserAdminRuleException::class);

        $this->service->setActive($admin->id(), false, $admin->id());
    }

    #[Test]
    public function testTheLastActiveAdminCannotBeDeactivated(): void
    {
        $admin = $this->user('admin@rehearsalbox.test', 'Admin', UserRole::Admin);
        $other = $this->user('autre@rehearsalbox.test', 'Autre', UserRole::Admin, false);

        try {
            $this->service->setActive($admin->id(), false, $other->id());
            self::fail('UserAdminRuleException attendue.');
        } catch (UserAdminRuleException) {
            self::assertTrue($this->users->findById($admin->id())->isActive());
        }
    }

    #[Test]
    public function testAnAdminCanDeactivateAnotherAdminWhenAnotherActiveOneRemains(): void
    {
        $first = $this->user('a@rehearsalbox.test', 'A', UserRole::Admin);
        $second = $this->user('b@rehearsalbox.test', 'B', UserRole::Admin);

        self::assertFalse($this->service->setActive($second->id(), false, $first->id())->isActive());
    }

    #[Test]
    public function testSetActiveOnUnknownUserThrowsNotFound(): void
    {
        $admin = $this->user('admin@rehearsalbox.test', 'Admin', UserRole::Admin);

        $this->expectException(UserNotFoundException::class);

        $this->service->setActive(9999, false, $admin->id());
    }

    // --- Déblocage --------------------------------------------------------------

    #[Test]
    public function testUnlockClearsTheLockAndTheFailedAttemptsButKeepsThePasswordAndSessions(): void
    {
        $alice = $this->user('alice@rehearsalbox.test', 'Alice');
        $locked = $this->users->save(new User($alice->id(), $alice->email(), $alice->passwordHash(), 'Alice', UserRole::Musicien, true, 5, new \DateTimeImmutable('+7 days'), 3));

        $unlocked = $this->service->unlock($locked->id());

        self::assertSame(0, $unlocked->failedLoginAttempts());
        self::assertNull($unlocked->lockedUntil());
        self::assertSame($locked->passwordHash(), $unlocked->passwordHash());
        self::assertSame(3, $unlocked->sessionVersion());
    }

    #[Test]
    public function testUnlockOnUnknownUserThrowsNotFound(): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->service->unlock(9999);
    }
}
