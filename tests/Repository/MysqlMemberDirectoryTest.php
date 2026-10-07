<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Account\Entity\UserRole;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Group\Repository\MysqlGroupRepository;
use App\Repository\MysqlMemberDirectory;
use App\Account\Repository\MysqlUserRepository;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Annuaire des membres pour la liste après « @ » : noms et groupes, jamais d'adresse e-mail. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class MysqlMemberDirectoryTest extends RepositoryTestCase
{
    private MysqlMemberDirectory $directory;
    private MysqlUserRepository $users;
    private MysqlGroupRepository $groups;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = new MysqlMemberDirectory($this->pdo);
        $this->users = new MysqlUserRepository($this->pdo);
        $this->groups = new MysqlGroupRepository($this->pdo);
    }

    private function user(string $name, bool $active = true): User
    {
        return $this->users->save(new User(0, strtolower(str_replace(' ', '.', $name)) . '@rehearsalbox.test', 'hash', $name, UserRole::Musicien, $active, 0, null));
    }

    private function join(User $user, string ...$groupNames): void
    {
        foreach ($groupNames as $name) {
            $group = null;
            foreach ($this->groups->findAll() as $existing) {
                if ($existing->name() === $name) {
                    $group = $existing;
                }
            }
            $group ??= $this->groups->save(new Group(0, $name, null, null, strtolower($name) . '@rehearsalbox.test'));
            $this->groups->addMember($group->id(), $user->id());
        }
    }

    /** @return list<string> */
    private function names(string $query, int $except = 0, int $limit = 10): array
    {
        return array_map(static fn ($s) => $s->name(), $this->directory->search($query, $except, $limit));
    }

    #[Test]
    public function testFindsActiveMembersByPartOfTheirNameWithTheirGroups(): void
    {
        $denis = $this->user('Denis Martin');
        $this->join($denis, 'Carnage', 'Alpha');
        $this->user('Bob');

        $found = $this->directory->search('deni', 0, 10);

        self::assertCount(1, $found);
        self::assertSame($denis->id(), $found[0]->id());
        self::assertSame('Denis Martin', $found[0]->name());
        self::assertSame(['Alpha', 'Carnage'], $found[0]->groupNames());
    }

    #[Test]
    public function testInactiveMembersAndTheRequesterAreNeverProposed(): void
    {
        $this->user('Denis Parti', active: false);
        $me = $this->user('Denis Moi');
        $this->user('Denis Autre');

        self::assertSame(['Denis Autre'], $this->names('denis', $me->id()));
    }

    #[Test]
    public function testTheNumberOfResultsIsCapped(): void
    {
        foreach (range(1, 15) as $i) {
            $this->user("Denis {$i}");
        }

        self::assertCount(10, $this->names('denis', 0, 10));
        self::assertCount(3, $this->names('denis', 0, 3));
    }

    #[Test]
    public function testWildcardsInTheQueryAreLiteralNotPatterns(): void
    {
        $this->user('Denis');
        $this->user('50% Metal');

        self::assertSame([], $this->names('%%'), 'le joker % ne renvoie pas tout le monde');
        self::assertSame([], $this->names('d_n'), 'le joker _ ne remplace pas une lettre');
        self::assertSame(['50% Metal'], $this->names('0% m'), 'un % saisi est cherché tel quel');
    }

    #[Test]
    public function testAMemberWithoutGroupHasAnEmptyGroupList(): void
    {
        $this->user('Solo');

        self::assertSame([], $this->directory->search('solo', 0, 10)[0]->groupNames());
    }

    #[Test]
    public function testASuggestionNeverExposesAnEmailAddress(): void
    {
        $this->user('Denis');

        $suggestion = $this->directory->search('denis', 0, 10)[0];

        self::assertStringNotContainsString('@', json_encode(get_object_vars($suggestion), JSON_THROW_ON_ERROR) . implode(' ', $suggestion->groupNames()) . $suggestion->name());
        self::assertFalse(method_exists($suggestion, 'email'));
    }
}
