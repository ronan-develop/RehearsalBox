<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Service\Direct;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Messaging\Repository\Mention\MysqlMemberDirectory;
use App\Messaging\Service\ConversationAccess;
use App\Messaging\Service\Direct\DirectMemberListService;
use App\Security\Exception\AccessDeniedException;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Scenarios\ConversationWorld;
use PHPUnit\Framework\Attributes\Test;

/** #269 : « Nouveau message » liste tous les membres actifs avec qui l'on peut écrire, sans adresse. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class DirectMemberListServiceTest extends RepositoryTestCase
{
    use ConversationWorld;

    private DirectMemberListService $list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
        $this->list = new DirectMemberListService(new MysqlMemberDirectory($this->pdo), $this->users);
    }

    #[Test]
    public function testListsEveryOtherActiveMemberByNameWithTheirGroupsAndNoOneElse(): void
    {
        [$alice] = $this->world();
        $this->user('Carol');
        $this->users->save(new User(0, 'away@rehearsalbox.test', 'hash', 'Away', UserRole::Musicien, false, 0, null));

        $members = $this->list->members($alice->id());

        self::assertSame(['Bob', 'Carol'], array_map(static fn ($m) => $m->name(), $members));
        self::assertSame(['Beta'], $members[0]->groupNames());
        self::assertSame([], $members[1]->groupNames(), 'un membre sans groupe est listé aussi');
    }

    #[Test]
    public function testTheRecipientOfAValidTargetIsReturned(): void
    {
        [$alice, $bob] = $this->world();

        self::assertSame('Bob', $this->list->recipient($alice->id(), $bob->id())->displayName());
    }

    #[Test]
    public function testYourselfAnUnknownAndAnInactiveTargetAreRefusedTheSameWay(): void
    {
        [$alice] = $this->world();
        $away = $this->users->save(new User(0, 'away@rehearsalbox.test', 'hash', 'Away', UserRole::Musicien, false, 0, null));

        foreach ([$alice->id(), 9999, $away->id()] as $target) {
            try {
                $this->list->recipient($alice->id(), $target);
                self::fail('Refus attendu pour ' . $target);
            } catch (AccessDeniedException $e) {
                self::assertSame(ConversationAccess::DENIED, $e->getMessage());
            }
        }
    }
}
