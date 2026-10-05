<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\MysqlConversationGuestRepository;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlMemberDirectory;
use App\Repository\MysqlUserRepository;
use App\Security\Exception\AccessDeniedException;
use App\Service\ConversationAccess;
use App\Service\MemberSearchService;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

/** #178 : la liste proposée après « @ » — tous les membres actifs, par nom, avec leur groupe ; jamais d'adresse. */
final class MemberSearchServiceTest extends RepositoryTestCase
{
    private MemberSearchService $service;
    private MysqlConversationGuestRepository $guests;
    /** @var array<string, User> */
    private array $people = [];
    private int $alpha;
    private int $beta;
    private int $carnage;
    private int $conversationId;

    protected function setUp(): void
    {
        parent::setUp();
        $users = new MysqlUserRepository($this->pdo);
        $groups = new MysqlGroupRepository($this->pdo);
        foreach (['alice', 'bob', 'denis', 'denise', 'erin'] as $name) {
            $this->people[$name] = $users->save(new User(0, "{$name}@rehearsalbox.test", 'hash', ucfirst($name), UserRole::Musicien, true, 0, null));
        }
        $this->alpha = $groups->save(new Group(0, 'Alpha', null, null, 'alpha@rehearsalbox.test'))->id();
        $this->beta = $groups->save(new Group(0, 'Beta', null, null, 'beta@rehearsalbox.test'))->id();
        $this->carnage = $groups->save(new Group(0, 'Carnage', null, null, 'carnage@rehearsalbox.test'))->id();
        $groups->addMember($this->alpha, $this->people['alice']->id());
        $groups->addMember($this->beta, $this->people['bob']->id());
        $groups->addMember($this->carnage, $this->people['denis']->id());
        $conversations = new MysqlConversationRepository($this->pdo);
        $this->guests = new MysqlConversationGuestRepository($this->pdo);
        $this->conversationId = $conversations->create($this->alpha, $this->beta, null, new \DateTimeImmutable('2026-10-06 12:00:00'), $this->people['alice']->id())->id();
        $this->service = new MemberSearchService(
            new MysqlMemberDirectory($this->pdo),
            new ConversationAccess($conversations, $groups, $this->guests),
            $groups,
            $this->guests,
        );
    }

    private function id(string $name): int
    {
        return $this->people[$name]->id();
    }

    /** @return array<string, bool> nom => participe déjà */
    private function inConversation(string $actor, string $query): array
    {
        $result = [];
        foreach ($this->service->search($this->id($actor), $query, $this->conversationId) as $choice) {
            $result[$choice['name']] = $choice['participant'];
        }

        return $result;
    }

    #[Test]
    public function testEveryActiveMemberIsProposedWithTheirGroupAndWhetherTheyAlreadyParticipate(): void
    {
        $found = $this->service->search($this->id('alice'), 'de', $this->conversationId);

        self::assertSame(['Denis', 'Denise'], array_column($found, 'name'));
        self::assertSame('Carnage', $found[0]['groups']);
        self::assertSame('', $found[1]['groups'], 'sans groupe : texte vide');
        self::assertSame([false, false], array_column($found, 'participant'));
        self::assertSame(['Bob' => true], $this->inConversation('alice', 'bob'), 'Bob est dans un des deux groupes');
    }

    #[Test]
    public function testAnInvitedGuestCountsAsAParticipant(): void
    {
        $this->guests->add($this->conversationId, $this->id('denis'), $this->id('alice'), new \DateTimeImmutable('2026-10-06 12:00:00'));

        self::assertTrue($this->inConversation('alice', 'denis')['Denis']);
    }

    #[Test]
    public function testTheRequesterIsNeverProposedAndShortQueriesGiveNothing(): void
    {
        self::assertArrayNotHasKey('Alice', $this->inConversation('alice', 'al'));
        self::assertSame([], $this->service->search($this->id('alice'), 'd', $this->conversationId));
        self::assertSame([], $this->service->search($this->id('alice'), '   ', $this->conversationId));
    }

    #[Test]
    public function testOnlyParticipantsCanSearchInsideAConversation(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->service->search($this->id('erin'), 'denis', $this->conversationId);
    }

    #[Test]
    public function testTheNewConversationPageSearchesWithTheSenderAndTargetGroups(): void
    {
        $found = $this->service->searchForNewConversation($this->id('alice'), 'bo', $this->alpha, $this->beta);

        self::assertSame(['Bob'], array_column($found, 'name'));
        self::assertSame([true], array_column($found, 'participant'), 'Bob est dans le groupe visé');
    }

    #[Test]
    public function testYouCannotSearchAsAGroupYouDoNotBelongTo(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->service->searchForNewConversation($this->id('erin'), 'bo', $this->alpha, $this->beta);
    }

    #[Test]
    public function testTheNumberOfProposalsIsCapped(): void
    {
        $users = new MysqlUserRepository($this->pdo);
        foreach (range(1, 15) as $i) {
            $users->save(new User(0, "max{$i}@rehearsalbox.test", 'hash', "Max {$i}", UserRole::Musicien, true, 0, null));
        }

        self::assertCount(MemberSearchService::MAX_RESULTS, $this->service->search($this->id('alice'), 'max', $this->conversationId));
    }
}
