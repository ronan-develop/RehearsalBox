<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Service\Direct;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Database\TransactionRunner;
use App\Messaging\Exception\ConversationRateLimitException;
use App\Messaging\Exception\ConversationValidationException;
use App\Messaging\Repository\ConversationRepositoryInterface as Box;
use App\Messaging\Repository\MysqlConversationMessageRepository;
use App\Messaging\Repository\MysqlConversationPresenceRepository;
use App\Messaging\Repository\MysqlConversationRepository;
use App\Messaging\Service\ConversationAccess;
use App\Messaging\Service\Direct\DirectConversationService;
use App\Security\Exception\AccessDeniedException;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\RecordingMailer;
use App\Tests\Scenarios\ConversationWorld;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class DirectConversationServiceTest extends RepositoryTestCase
{
    use ConversationWorld;

    private DirectConversationService $direct;
    private MysqlConversationRepository $conversations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
        $this->conversations = new MysqlConversationRepository($this->pdo);
        $this->direct = new DirectConversationService(
            $this->conversations,
            new MysqlConversationMessageRepository($this->pdo),
            new MysqlConversationPresenceRepository($this->pdo),
            $this->users,
            new TransactionRunner($this->pdo),
            $this->clock,
        );
    }

    private function conversationCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn();
    }

    #[Test]
    public function testStartCreatesTheConversationAndTheFirstMessageSeenByBothUnderTheOtherName(): void
    {
        [$alice, $bob] = $this->world();

        $conversation = $this->direct->start($alice->id(), $bob->id(), " Salut Bob\n ");

        self::assertTrue($conversation->isDirect());
        $forBob = $this->reader->open($bob->id(), $conversation->id());
        self::assertSame('Alice', $forBob->displayTitle());
        self::assertSame(['Salut Bob'], array_map(static fn ($m) => $m->body(), $forBob->messages()));
        self::assertSame('Bob', $this->reader->open($alice->id(), $conversation->id())->displayTitle());
    }

    #[Test]
    public function testStartingAgainWithTheSamePersonReopensTheSameConversation(): void
    {
        [$alice, $bob] = $this->world();

        $first = $this->direct->start($alice->id(), $bob->id(), 'Un');
        $second = $this->direct->start($bob->id(), $alice->id(), 'Deux');

        self::assertSame($first->id(), $second->id());
        self::assertSame(1, $this->conversationCount());
        self::assertSame(['Un', 'Deux'], array_map(static fn ($m) => $m->body(), $this->reader->open($alice->id(), $first->id())->messages()));
    }

    #[Test]
    public function testWritingToOneselfAnUnknownOrAnInactivePersonIsRefusedTheSameWayAndCreatesNothing(): void
    {
        [$alice] = $this->world();
        $away = $this->users->save(new User(0, 'away@rehearsalbox.test', 'hash', 'Away', UserRole::Musicien, false, 0, null));

        foreach ([$alice->id(), 9999, $away->id()] as $target) {
            try {
                $this->direct->start($alice->id(), $target, 'Salut');
                self::fail('Refus attendu pour ' . $target);
            } catch (AccessDeniedException $e) {
                self::assertSame(ConversationAccess::DENIED, $e->getMessage());
            }
        }
        self::assertSame(0, $this->conversationCount());
    }

    #[Test]
    public function testAnInactiveSenderIsRefused(): void
    {
        [, $bob] = $this->world();
        $away = $this->users->save(new User(0, 'away@rehearsalbox.test', 'hash', 'Away', UserRole::Musicien, false, 0, null));

        $this->expectException(AccessDeniedException::class);
        $this->direct->start($away->id(), $bob->id(), 'Salut');
    }

    #[Test]
    public function testAnInvalidMessageCreatesNothing(): void
    {
        [$alice, $bob] = $this->world();

        $this->expectException(ConversationValidationException::class);
        try {
            $this->direct->start($alice->id(), $bob->id(), '   ');
        } finally {
            self::assertSame(0, $this->conversationCount());
        }
    }

    #[Test]
    public function testTheHourlyLimitApplies(): void
    {
        [$alice, $bob] = $this->world();
        for ($i = 0; $i < 30; $i++) {
            $this->direct->start($alice->id(), $bob->id(), 'Message ' . $i);
        }

        $this->expectException(ConversationRateLimitException::class);
        $this->direct->start($alice->id(), $bob->id(), 'Trop');
    }

    #[Test]
    public function testAThirdPersonSeesNothingAndGetsTheSameRefusalAsForAMissingThread(): void
    {
        [$alice, $bob] = $this->world();
        $carol = $this->user('Carol');
        $conversation = $this->direct->start($alice->id(), $bob->id(), 'Privé');

        foreach ([$conversation->id(), 9999] as $id) {
            try {
                $this->reader->open($carol->id(), $id);
                self::fail('Refus attendu');
            } catch (AccessDeniedException $e) {
                self::assertSame(ConversationAccess::DENIED, $e->getMessage());
            }
        }
        self::assertSame([], $this->reader->listFor($carol->id(), Box::BOX_ACTIVE));
    }

    #[Test]
    public function testBothPeopleCanReplyAndTheReplyIsUnreadForTheOther(): void
    {
        [$alice, $bob] = $this->world();
        $conversation = $this->direct->start($alice->id(), $bob->id(), 'Salut');

        $this->clock->sleep(60);
        $this->service->reply($bob->id(), $conversation->id(), 'Salut Alice');

        $list = $this->reader->listFor($alice->id(), Box::BOX_ACTIVE);
        self::assertCount(1, $list);
        self::assertTrue($list[0]->isUnread());
        self::assertSame('Bob', $list[0]->displayTitle());
    }

    #[Test]
    public function testAPersonWhoIsNotOneOfTheTwoCannotReply(): void
    {
        [$alice, $bob] = $this->world();
        $carol = $this->user('Carol');
        $conversation = $this->direct->start($alice->id(), $bob->id(), 'Salut');

        $this->expectException(AccessDeniedException::class);
        $this->service->reply($carol->id(), $conversation->id(), 'Intrus');
    }

    #[Test]
    public function testMentionsAreIgnoredAndNobodyIsInvitedToADirectConversation(): void
    {
        [$alice, $bob] = $this->world();
        $carol = $this->user('Carol');
        $conversation = $this->direct->start($alice->id(), $bob->id(), 'Salut');

        $this->service->reply($bob->id(), $conversation->id(), 'Coucou @Carol', [$carol->id()]);

        self::assertSame(2, $this->conversations->participantCount($conversation->id()));
        self::assertSame([], $this->reader->listFor($carol->id(), Box::BOX_ACTIVE));
    }

    #[Test]
    public function testADirectConversationCannotBeRenamed(): void
    {
        [$alice, $bob] = $this->world();
        $conversation = $this->direct->start($alice->id(), $bob->id(), 'Salut');

        $this->expectException(ConversationValidationException::class);
        $this->service->rename($alice->id(), $conversation->id(), 'Nouveau titre');
    }

    // --- E-mail à l'autre personne ---------------------------------------------------------------------

    /** @return array{DirectConversationService, \App\Messaging\Service\ConversationService, RecordingMailer} */
    private function withMail(): array
    {
        $mailer = new RecordingMailer();
        $notifier = new \App\Messaging\Notification\DirectMessageNotifier(
            \App\Tests\Scenarios\TestMailbox::of($mailer),
            new \App\Messaging\Repository\Notice\MysqlMentionNoticeRepository($this->pdo),
            $this->users,
            new \App\Messaging\Repository\Participation\MysqlConversationMuteRepository($this->pdo),
        );
        $messages = new MysqlConversationMessageRepository($this->pdo);
        $presence = new MysqlConversationPresenceRepository($this->pdo);
        $transactions = new TransactionRunner($this->pdo);
        $direct = new DirectConversationService($this->conversations, $messages, $presence, $this->users, $transactions, $this->clock, notifier: $notifier);
        $service = new \App\Messaging\Service\ConversationService($this->conversations, $messages, $presence, $this->groups, $transactions, $this->clock, directNotifier: $notifier);

        return [$direct, $service, $mailer];
    }

    #[Test]
    public function testStartingEmailsTheOtherPersonOnceAndARefusedStartEmailsNobody(): void
    {
        [$direct, , $mailer] = $this->withMail();
        [$alice, $bob] = $this->world();

        try {
            $direct->start($alice->id(), $alice->id(), 'Moi-même');
        } catch (AccessDeniedException) {
        }
        self::assertSame([], $mailer->sent);

        $direct->start($alice->id(), $bob->id(), 'Salut');
        self::assertCount(1, $mailer->sent);
        self::assertSame('bob@rehearsalbox.test', $mailer->sent[0]->getTo()[0]->getAddress());
    }

    #[Test]
    public function testAReplyEmailsTheOtherPerson(): void
    {
        [$direct, $service, $mailer] = $this->withMail();
        [$alice, $bob] = $this->world();
        $conversation = $direct->start($alice->id(), $bob->id(), 'Salut');

        $service->reply($bob->id(), $conversation->id(), 'Salut Alice');

        self::assertCount(2, $mailer->sent);
        self::assertSame('alice@rehearsalbox.test', $mailer->sent[1]->getTo()[0]->getAddress());
    }
}
