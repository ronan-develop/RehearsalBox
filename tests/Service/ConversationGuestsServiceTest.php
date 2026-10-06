<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Database\TransactionRunner;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\Contract\ConversationRepositoryInterface as Box;
use App\Repository\MysqlConversationAlertRepository;
use App\Repository\MysqlConversationGuestRepository;
use App\Repository\MysqlConversationMentionRepository;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlConversationPresenceRepository;
use App\Repository\MysqlConversationMessageRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Security\Exception\AccessDeniedException;
use App\Service\ConversationAccess;
use App\Service\ConversationGuestService;
use App\Service\ConversationMentionService;
use App\Service\ConversationService;
use App\Service\ConversationTrashService;
use App\Service\MentionNotifier;
use App\Tests\Support\RecordingMailer;
use App\Service\Exception\ConversationValidationException;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

/** #178 : taguer un membre du site lui ouvre CETTE conversation ; les invités y participent comme les autres. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class ConversationGuestsServiceTest extends RepositoryTestCase
{
    private MockClock $clock;
    private ConversationService $service;
    private \App\Service\ConversationReader $reader;
    private ConversationTrashService $trash;
    private ConversationGuestService $guestService;
    private RecordingMailer $mailer;
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
        $this->clock = new MockClock('2026-10-04 12:00:00');
        $users = new MysqlUserRepository($this->pdo);
        $groups = new MysqlGroupRepository($this->pdo);
        foreach (['alice', 'bob', 'denis', 'erin'] as $name) {
            $this->people[$name] = $users->save(new User(0, "{$name}@rehearsalbox.test", 'hash', ucfirst($name), UserRole::Musicien, true, 0, null));
        }
        $this->alpha = $groups->save(new Group(0, 'Alpha', null, null, 'alpha@rehearsalbox.test'))->id();
        $this->beta = $groups->save(new Group(0, 'Beta', null, null, 'beta@rehearsalbox.test'))->id();
        $carnage = $groups->save(new Group(0, 'Carnage', null, null, 'carnage@rehearsalbox.test'))->id();
        $groups->addMember($this->alpha, $this->people['alice']->id());
        $groups->addMember($this->beta, $this->people['bob']->id());
        $groups->addMember($carnage, $this->people['denis']->id());
        $this->conversations = new MysqlConversationRepository($this->pdo);
        $this->messages = new MysqlConversationMessageRepository($this->pdo);
        $this->presence = new MysqlConversationPresenceRepository($this->pdo);
        $this->guests = new MysqlConversationGuestRepository($this->pdo);
        $this->mentions = new MysqlConversationMentionRepository($this->pdo);
        $this->mailer = new RecordingMailer();
        $transactions = new TransactionRunner($this->pdo);
        $access = new ConversationAccess($this->conversations, $groups, $this->guests);
        $mentionService = new ConversationMentionService(
            $users,
            $groups,
            $this->guests,
            $this->mentions,
            $this->messages,
            new MentionNotifier($this->mailer, new \App\Repository\MysqlMentionNoticeRepository($this->pdo), $users, new \App\Repository\MysqlNotificationPreferenceRepository($this->pdo), new \App\Repository\MysqlConversationMuteRepository($this->pdo), 'no-reply@rehearsalbox.example', 'https://rehearsalbox.example'),
        );
        $this->service = new ConversationService(
            $this->conversations,
            $this->messages,
            $this->presence,
            $groups,
            $transactions,
            $this->clock,
            mentions: $mentionService,
            access: $access,
        );
        $this->reader = new \App\Service\ConversationReader(
            $access,
            new \App\Service\ConversationThreadBuilder($this->conversations, $this->messages, $this->presence, $groups, $this->clock, $mentionService),
            $this->conversations,
            $this->messages,
            $this->presence,
            $this->clock,
            $mentionService,
        );
        $this->trash = new ConversationTrashService($access, new \App\Repository\MysqlConversationTrashRepository($this->pdo), $transactions, $this->clock, new MysqlConversationAlertRepository($this->pdo));
        $this->guestService = new ConversationGuestService($access, $this->guests, $this->messages, $users, $transactions, $this->clock);
        $this->conversationId = $this->service->start($this->id('alice'), $this->alpha, $this->beta, 'Bonjour', 'Concert')->id();
    }

    private function id(string $name): int
    {
        return $this->people[$name]->id();
    }

    private function denied(callable $action): void
    {
        try {
            $action();
            self::fail('AccessDeniedException attendue');
        } catch (AccessDeniedException $e) {
            self::assertSame('Accès refusé.', $e->getMessage());
        }
    }

    private function tagDenis(): void
    {
        $this->service->reply($this->id('alice'), $this->conversationId, 'Viens voir @Denis', [$this->id('denis')]);
    }

    #[Test]
    public function testTaggingSomeoneFromAThirdGroupOpensTheConversationToThemOnly(): void
    {
        $this->denied(fn () => $this->reader->open($this->id('denis'), $this->conversationId));

        $this->tagDenis();

        $thread = $this->reader->open($this->id('denis'), $this->conversationId);
        self::assertSame('Concert', $thread->conversation()->title());
        self::assertCount(1, $this->reader->listFor($this->id('denis'), Box::BOX_ACTIVE));
        $other = $this->service->start($this->id('alice'), $this->alpha, $this->beta, 'Autre fil')->id();
        $this->denied(fn () => $this->reader->open($this->id('denis'), $other));
    }

    #[Test]
    public function testTheFeedAnnouncesTheNewcomerAndKeepsTheMention(): void
    {
        $this->tagDenis();

        $messages = $this->messages->messagesOf($this->conversationId);
        $lines = array_map(static fn ($m) => ($m->isSystem() ? '[système] ' : '') . $m->body(), $messages);
        self::assertSame(['Bonjour', '[système] a ajouté Denis à la conversation', 'Viens voir @Denis'], $lines);
        $last = $messages[array_key_last($messages)];
        self::assertSame([$this->id('denis') => '@Denis'], $this->mentions->forMessages([$last->id()])[$last->id()]);
    }

    #[Test]
    public function testTaggingSomeoneSendsThemOneEmailAfterTheMessageIsSaved(): void
    {
        $this->tagDenis();

        self::assertCount(1, $this->mailer->sent);
        self::assertSame('denis@rehearsalbox.test', $this->mailer->sent[0]->getTo()[0]->getAddress());
        self::assertStringContainsString('/messages/' . $this->conversationId, (string) $this->mailer->sent[0]->getTextBody());
        self::assertStringNotContainsString('Concert', (string) $this->mailer->sent[0]->getTextBody() . $this->mailer->sent[0]->getSubject(), 'le titre n\'est jamais envoyé');
    }

    #[Test]
    public function testTaggingAnExistingParticipantAlsoNotifiesThemButNotWithoutATag(): void
    {
        $this->service->reply($this->id('alice'), $this->conversationId, 'Sans tag');
        self::assertSame([], $this->mailer->sent);

        $this->service->reply($this->id('alice'), $this->conversationId, 'Salut @Bob', [$this->id('bob')]);

        self::assertCount(1, $this->mailer->sent);
        self::assertSame('bob@rehearsalbox.test', $this->mailer->sent[0]->getTo()[0]->getAddress());
    }

    #[Test]
    public function testTheFirstMessageOfANewConversationNotifiesTheTaggedPersonToo(): void
    {
        $this->service->start($this->id('alice'), $this->alpha, $this->beta, 'Avec @Denis', null, [$this->id('denis')]);

        self::assertCount(1, $this->mailer->sent);
        self::assertSame('denis@rehearsalbox.test', $this->mailer->sent[0]->getTo()[0]->getAddress());
    }

    #[Test]
    public function testTenTagsInARowSendOnlyOneEmail(): void
    {
        foreach (range(1, 3) as $i) {
            $this->service->reply($this->id('alice'), $this->conversationId, "Encore @Denis {$i}", [$this->id('denis')]);
        }

        self::assertCount(1, $this->mailer->sent);
    }

    #[Test]
    public function testTheThreadCarriesTheMentionsOfItsMessages(): void
    {
        $this->tagDenis();

        $thread = $this->reader->open($this->id('denis'), $this->conversationId);

        $tagged = array_values(array_filter($thread->messages(), static fn ($m) => !$m->isSystem() && $m->body() === 'Viens voir @Denis'))[0];
        self::assertSame([$this->id('denis') => '@Denis'], $thread->mentions()[$tagged->id()]);
        self::assertCount(1, $thread->mentions(), 'les messages sans mention sont absents');
    }

    #[Test]
    public function testAGuestReadsRepliesAndSeesTheOthers(): void
    {
        $this->tagDenis();

        $this->service->reply($this->id('denis'), $this->conversationId, 'Je suis là');
        $this->reader->typing($this->id('denis'), $this->conversationId);
        $thread = $this->reader->poll($this->id('bob'), $this->conversationId, 0);

        self::assertSame('Je suis là', $thread->messages()[array_key_last($thread->messages())]->body());
        self::assertSame(['Denis'], $thread->typing());
    }

    #[Test]
    public function testTaggingAParticipantDoesNotInviteAnyone(): void
    {
        $this->service->reply($this->id('alice'), $this->conversationId, 'Salut @Bob', [$this->id('bob')]);

        self::assertFalse($this->guests->isGuest($this->conversationId, $this->id('bob')));
        self::assertCount(2, $this->messages->messagesOf($this->conversationId), 'aucune ligne « a ajouté »');
    }

    #[Test]
    public function testTheFirstMessageOfANewConversationCanTagSomeone(): void
    {
        $id = $this->service->start($this->id('alice'), $this->alpha, $this->beta, 'Avec @Denis', null, [$this->id('denis')])->id();

        self::assertTrue($this->guests->isGuest($id, $this->id('denis')));
        $this->reader->open($this->id('denis'), $id);
    }

    #[Test]
    public function testSomeoneWhoIsNotInTheConversationCannotTagAnyone(): void
    {
        $this->denied(fn () => $this->service->reply($this->id('erin'), $this->conversationId, '@Denis', [$this->id('denis')]));
        self::assertFalse($this->guests->isGuest($this->conversationId, $this->id('denis')));
    }

    #[Test]
    public function testAGuestCannotBringInAThirdPerson(): void
    {
        $this->tagDenis();

        $this->expectException(ConversationValidationException::class);
        $this->service->reply($this->id('denis'), $this->conversationId, 'Et @Erin', [$this->id('erin')]);
    }

    #[Test]
    public function testTheAdderTheInitiatorOrTheGuestHimselfCanRemoveTheGuest(): void
    {
        foreach (['alice', 'denis'] as $remover) {
            $this->tagDenis();
            $this->guestService->remove($this->id($remover), $this->conversationId, $this->id('denis'));
            self::assertFalse($this->guests->isGuest($this->conversationId, $this->id('denis')), $remover);
            $this->denied(fn () => $this->reader->open($this->id('denis'), $this->conversationId));
        }
        $lines = array_map(static fn ($m) => $m->body(), array_filter($this->messages->messagesOf($this->conversationId), static fn ($m) => $m->isSystem()));
        self::assertContains('a retiré Denis de la conversation', $lines);
        self::assertContains('a quitté la conversation', $lines);
    }

    #[Test]
    public function testSomeoneElseCannotRemoveTheGuest(): void
    {
        $this->tagDenis();

        $this->denied(fn () => $this->guestService->remove($this->id('bob'), $this->conversationId, $this->id('denis')));
        $this->denied(fn () => $this->guestService->remove($this->id('erin'), $this->conversationId, $this->id('denis')));
        self::assertTrue($this->guests->isGuest($this->conversationId, $this->id('denis')));
    }

    #[Test]
    public function testRemovingSomeoneWhoIsNotAGuestIsRefused(): void
    {
        $this->denied(fn () => $this->guestService->remove($this->id('alice'), $this->conversationId, $this->id('bob')));
    }

    #[Test]
    public function testAGuestCannotTrashTheConversationButIsWarnedWhenItIsTrashed(): void
    {
        $this->tagDenis();
        $this->denied(fn () => $this->trash->delete($this->id('denis'), $this->conversationId));

        $this->trash->delete($this->id('alice'), $this->conversationId);

        self::assertSame([], $this->reader->listFor($this->id('denis'), Box::BOX_ACTIVE));
        self::assertCount(1, $this->trash->alertsFor($this->id('denis')), 'les invités sont prévenus comme les autres participants');
    }
}
