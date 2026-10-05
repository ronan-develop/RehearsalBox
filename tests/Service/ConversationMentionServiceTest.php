<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\MysqlConversationGuestRepository;
use App\Repository\MysqlConversationMentionRepository;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlConversationPresenceRepository;
use App\Repository\MysqlConversationMessageRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Service\ConversationMentionService;
use App\Service\Exception\ConversationValidationException;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

/** #178 : valider les personnes mentionnées, séparer participants et extérieurs, inviter les extérieurs. */
final class ConversationMentionServiceTest extends RepositoryTestCase
{
    private \DateTimeImmutable $now;
    private ConversationMentionService $service;
    private MysqlConversationRepository $conversations;
    private MysqlConversationMessageRepository $messages;
    private MysqlConversationPresenceRepository $presence;
    private MysqlConversationGuestRepository $guests;
    private MysqlConversationMentionRepository $mentions;
    /** @var array<string, User> */
    private array $people = [];
    private int $alpha;
    private int $beta;
    private int $conversationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-10-06 12:00:00');
        $users = new MysqlUserRepository($this->pdo);
        $groups = new MysqlGroupRepository($this->pdo);
        foreach (['alice', 'carole', 'bob', 'denis'] as $name) {
            $this->people[$name] = $users->save(new User(0, "{$name}@rehearsalbox.test", 'hash', ucfirst($name), UserRole::Musicien, true, 0, null));
        }
        $this->people['zoe'] = $users->save(new User(0, 'zoe@rehearsalbox.test', 'hash', 'Zoé', UserRole::Musicien, false, 0, null));
        $this->alpha = $groups->save(new Group(0, 'Alpha', null, null, 'alpha@rehearsalbox.test'))->id();
        $this->beta = $groups->save(new Group(0, 'Beta', null, null, 'beta@rehearsalbox.test'))->id();
        $carnage = $groups->save(new Group(0, 'Carnage', null, null, 'carnage@rehearsalbox.test'))->id();
        $groups->addMember($this->alpha, $this->people['alice']->id());
        $groups->addMember($this->alpha, $this->people['carole']->id());
        $groups->addMember($this->beta, $this->people['bob']->id());
        $groups->addMember($carnage, $this->people['denis']->id());
        $this->conversations = new MysqlConversationRepository($this->pdo);
        $this->messages = new MysqlConversationMessageRepository($this->pdo);
        $this->presence = new MysqlConversationPresenceRepository($this->pdo);
        $this->guests = new MysqlConversationGuestRepository($this->pdo);
        $this->mentions = new MysqlConversationMentionRepository($this->pdo);
        $this->service = new ConversationMentionService($users, $groups, $this->guests, $this->mentions, $this->messages);
        $this->conversationId = $this->conversations->create($this->alpha, $this->beta, null, $this->now, $this->people['alice']->id())->id();
    }

    private function id(string $name): int
    {
        return $this->people[$name]->id();
    }

    /** @param list<mixed> $ids */
    private function plan(string $actor, string $body, array $ids, ?int $conversationId = null)
    {
        return $this->service->plan($this->id($actor), $this->alpha, $this->beta, $conversationId ?? $this->conversationId, $body, $ids);
    }

    #[Test]
    public function testAParticipantIsMentionedWithoutBecomingAGuest(): void
    {
        $plan = $this->plan('alice', 'Salut @Bob', [$this->id('bob')]);

        self::assertSame([$this->id('bob') => '@Bob'], $plan->labels());
        self::assertSame([], $plan->outsiders());
    }

    #[Test]
    public function testSomeoneOutsideTheTwoGroupsIsListedAsAnOutsider(): void
    {
        $plan = $this->plan('alice', 'Viens @Denis', [$this->id('denis')]);

        self::assertSame([$this->id('denis') => 'Denis'], $plan->outsiders());
        self::assertSame([$this->id('denis') => '@Denis'], $plan->labels());
    }

    #[Test]
    public function testAnAlreadyInvitedGuestIsNoLongerAnOutsider(): void
    {
        $this->guests->add($this->conversationId, $this->id('denis'), $this->id('alice'), $this->now);

        self::assertSame([], $this->plan('bob', '@Denis', [$this->id('denis')])->outsiders());
    }

    #[Test]
    public function testAMentionWhoseLabelIsNotInTheTextIsIgnored(): void
    {
        $plan = $this->plan('alice', 'Rien à voir', [$this->id('denis'), $this->id('bob')]);

        self::assertTrue($plan->isEmpty(), 'sans « @Nom » dans le texte, ni mention ni invitation');
    }

    #[Test]
    public function testYourselfAndDuplicatesAreIgnored(): void
    {
        $plan = $this->plan('alice', '@Alice @Bob @Bob', [$this->id('alice'), $this->id('bob'), (string) $this->id('bob')]);

        self::assertSame([$this->id('bob') => '@Bob'], $plan->labels());
    }

    #[Test]
    public function testUnknownInactiveOrMalformedIdsAreRefusedWithAClearMessage(): void
    {
        foreach ([[999999], [$this->id('zoe')], ['abc'], [[1]], [0], [-3]] as $ids) {
            try {
                $this->plan('alice', '@Zoé', $ids);
                self::fail('refus attendu : ' . json_encode($ids));
            } catch (ConversationValidationException $e) {
                self::assertArrayHasKey('mentions', $e->fields());
            }
        }
    }

    #[Test]
    public function testTooManyMentionsInOneMessageAreRefused(): void
    {
        $this->expectException(ConversationValidationException::class);

        $this->plan('alice', 'beaucoup', range(1, 11));
    }

    #[Test]
    public function testOnlyGroupMembersMayAddSomeoneFromOutside(): void
    {
        $this->guests->add($this->conversationId, $this->id('denis'), $this->id('alice'), $this->now);
        $erin = (new MysqlUserRepository($this->pdo))->save(new User(0, 'erin@rehearsalbox.test', 'hash', 'Erin', UserRole::Musicien, true, 0, null));

        // Denis (invité) peut mentionner un participant, mais pas faire entrer une troisième personne.
        self::assertSame([$this->id('bob') => '@Bob'], $this->plan('denis', '@Bob', [$this->id('bob')])->labels());
        $this->expectException(ConversationValidationException::class);
        $this->service->plan($this->id('denis'), $this->alpha, $this->beta, $this->conversationId, '@Erin', [$erin->id()]);
    }

    #[Test]
    public function testApplyingInvitesTheOutsidersWithASystemLineAndRecordsTheMentions(): void
    {
        $plan = $this->plan('alice', 'Viens @Denis et @Bob', [$this->id('denis'), $this->id('bob')]);

        $this->service->addGuests($plan, $this->id('alice'), $this->conversationId, $this->now);
        $message = $this->messages->addMessage($this->conversationId, $this->id('alice'), 'Viens @Denis et @Bob', $this->now);
        $this->service->record($plan, $message->id());

        self::assertTrue($this->guests->isGuest($this->conversationId, $this->id('denis')));
        self::assertFalse($this->guests->isGuest($this->conversationId, $this->id('bob')));
        self::assertSame($this->id('alice'), $this->guests->addedBy($this->conversationId, $this->id('denis')));
        $lines = array_map(static fn ($m) => [$m->isSystem(), $m->body()], $this->messages->messagesOf($this->conversationId));
        self::assertContains([true, 'a ajouté Denis à la conversation'], $lines);
        self::assertSame(
            [$this->id('bob') => '@Bob', $this->id('denis') => '@Denis'],
            $this->mentions->forMessages([$message->id()])[$message->id()],
        );
    }
}
