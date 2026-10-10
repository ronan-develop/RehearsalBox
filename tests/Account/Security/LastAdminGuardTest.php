<?php

declare(strict_types=1);

namespace App\Tests\Account\Security;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Exception\UserAdminRuleException;
use App\Account\Repository\MysqlUserRepository;
use App\Account\Security\LastAdminGuard;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

/** #272 : on ne retire jamais les droits ni l'accès du DERNIER administrateur actif, ni de soi-même ; la vérification verrouille les administrateurs. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class LastAdminGuardTest extends RepositoryTestCase
{
    private MysqlUserRepository $users;
    private LastAdminGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->guard = new LastAdminGuard($this->users);
    }

    private function user(string $name, UserRole $role = UserRole::Admin, bool $active = true): User
    {
        return $this->users->save(new User(0, "{$name}@rehearsalbox.test", 'h', ucfirst($name), $role, $active, 0, null));
    }

    private function inTransaction(callable $action): void
    {
        $this->pdo->beginTransaction();
        try {
            $action();
        } finally {
            $this->pdo->rollBack();
        }
    }

    private function refused(User $target, int $actorId, string $verb, string $message): void
    {
        $this->inTransaction(function () use ($target, $actorId, $verb, $message): void {
            try {
                $this->guard->assertMayLoseAdmin($target, $actorId, $verb);
                self::fail('Refus attendu');
            } catch (UserAdminRuleException $e) {
                self::assertSame($message, $e->getMessage());
            }
        });
    }

    #[Test]
    public function testTheLastActiveAdminCannotLoseItsAccess(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob', UserRole::Admin, false); // inactif : ne compte pas
        $this->user('carole', UserRole::Musicien);

        $this->refused($alice, $bob->id(), 'désactiver', 'Impossible de désactiver le dernier administrateur actif.');
        $this->refused($alice, $bob->id(), 'rétrograder', 'Impossible de rétrograder le dernier administrateur actif.');
    }

    #[Test]
    public function testYouCannotRemoveYourOwnAdminAccessEvenWithAnotherAdmin(): void
    {
        $alice = $this->user('alice');
        $this->user('bob');

        $this->refused($alice, $alice->id(), 'désactiver', 'Vous ne pouvez pas désactiver votre propre compte.');
        $this->refused($alice, $alice->id(), 'rétrograder', 'Vous ne pouvez pas rétrograder votre propre compte.');
    }

    #[Test]
    public function testWithAnotherActiveAdminTheOneAccessMayBeRemoved(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');

        $this->inTransaction(function () use ($alice, $bob): void {
            $this->guard->assertMayLoseAdmin($alice, $bob->id(), 'désactiver');
            $this->addToAssertionCount(1);
        });
    }

    #[Test]
    public function testAMusicianOrAnInactiveAdminTargetIsNotConcernedByTheLastAdminRule(): void
    {
        $alice = $this->user('alice');
        $musician = $this->user('carole', UserRole::Musicien);
        $inactive = $this->user('dan', UserRole::Admin, false);

        $this->inTransaction(function () use ($alice, $musician, $inactive): void {
            $this->guard->assertMayLoseAdmin($musician, $alice->id(), 'désactiver');
            $this->guard->assertMayLoseAdmin($inactive, $alice->id(), 'désactiver');
            $this->addToAssertionCount(2);
        });
    }
}
