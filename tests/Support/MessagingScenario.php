<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;

/**
 * Décor commun des tests de dépôts de la messagerie : une horloge fixe, des personnes et des groupes.
 * À utiliser dans une classe qui étend RepositoryTestCase (accès à $this->pdo) et appelle setUpScenario() dans setUp().
 */
trait MessagingScenario
{
    private \DateTimeImmutable $now;
    private \DateTimeImmutable $cutoff;
    private MysqlGroupRepository $groups;
    private MysqlUserRepository $users;

    private function setUpScenario(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-04 12:00:00');
        $this->cutoff = $this->now->modify('-30 days');
        $this->groups = new MysqlGroupRepository($this->pdo);
        $this->users = new MysqlUserRepository($this->pdo);
    }

    private function user(string $name): User
    {
        return $this->users->save(new User(0, strtolower($name) . '@rehearsalbox.test', 'hash', $name, UserRole::Musicien, true, 0, null));
    }

    private function group(string $name, User ...$members): Group
    {
        $group = $this->groups->save(new Group(0, $name, null, null, strtolower($name) . '@rehearsalbox.test'));
        foreach ($members as $member) {
            $this->groups->addMember($group->id(), $member->id());
        }

        return $group;
    }

    private function at(string $modifier): \DateTimeImmutable
    {
        return $this->now->modify($modifier);
    }

    /** @return array{User, User, Group, Group} Alice (Alpha) et Bob (Beta) */
    private function pair(): array
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');

        return [$alice, $bob, $this->group('Alpha', $alice), $this->group('Beta', $bob)];
    }
}
